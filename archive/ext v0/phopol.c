#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "php_phopol.h"

/* =======================================================================
 * GLOBAL LAYOUT REGISTRY
 *
 * Persistent hash table: layout name (string) → phopol_layout_t *
 * Allocated in MINIT with pemalloc so it survives across requests.
 * Layouts registered by phopol_register_layout() are also persistent
 * (pemalloc / pestrndup) so they never dangle.
 * ===================================================================== */
static HashTable *phopol_layouts = NULL;

static void phopol_layout_dtor(zval *zv)
{
    phopol_layout_t *layout = (phopol_layout_t *)Z_PTR_P(zv);
    uint32_t i;
    for (i = 0; i < layout->field_count; i++) {
        pefree((void *)layout->fields[i].name, 1);
    }
    pefree(layout->fields, 1);
    pefree((void *)layout->name, 1);
    pefree(layout, 1);
}

/* =======================================================================
 * FIELD ENCODE / DECODE
 * ===================================================================== */

static zval *phopol_decode_field(uint8_t *data, phopol_field_t *field, zval *rv)
{
    uint8_t *ptr = data + field->offset;

    switch (field->type) {

        case PHOPOL_TYPE_DISPLAY:
            if (field->digits == 0) {
                ZVAL_STRINGL(rv, (char *)ptr, field->length);
            } else {
                char buf[32];
                size_t len = field->length < sizeof(buf) - 1
                           ? field->length : sizeof(buf) - 1;
                memcpy(buf, ptr, len);
                buf[len] = '\0';
                ZVAL_LONG(rv, (zend_long)atoll(buf));
            }
            break;

        case PHOPOL_TYPE_BINARY:
        case PHOPOL_TYPE_NATIVE:
            if (field->flags & PHOPOL_FLAG_SIGNED) {
                switch (field->length) {
                    case 2: { int16_t v; memcpy(&v, ptr, 2); ZVAL_LONG(rv, v); break; }
                    case 4: { int32_t v; memcpy(&v, ptr, 4); ZVAL_LONG(rv, v); break; }
                    case 8: { int64_t v; memcpy(&v, ptr, 8); ZVAL_LONG(rv, v); break; }
                    default: ZVAL_LONG(rv, 0);
                }
            } else {
                switch (field->length) {
                    case 2: { uint16_t v; memcpy(&v, ptr, 2); ZVAL_LONG(rv, (zend_long)v); break; }
                    case 4: { uint32_t v; memcpy(&v, ptr, 4); ZVAL_LONG(rv, (zend_long)v); break; }
                    case 8: { uint64_t v; memcpy(&v, ptr, 8); ZVAL_LONG(rv, (zend_long)v); break; }
                    default: ZVAL_LONG(rv, 0);
                }
            }
            break;

        case PHOPOL_TYPE_FLOAT32: {
            float v; memcpy(&v, ptr, 4); ZVAL_DOUBLE(rv, (double)v); break;
        }
        case PHOPOL_TYPE_FLOAT64: {
            double v; memcpy(&v, ptr, 8); ZVAL_DOUBLE(rv, v); break;
        }

        case PHOPOL_TYPE_PACKED: {
            /* BCD: two digits per byte; last nibble = sign (0x0D → negative) */
            int64_t val = 0;
            int     sign = 1;
            size_t  plen = field->length;
            size_t  pi;
            for (pi = 0; pi < plen; pi++) {
                uint8_t b  = ptr[pi];
                uint8_t hi = (b >> 4) & 0x0F;
                uint8_t lo = b & 0x0F;
                if (pi == plen - 1) {
                    val = val * 10 + hi;
                    if (lo == 0x0D) sign = -1;
                } else {
                    val = val * 10 + hi;
                    val = val * 10 + lo;
                }
            }
            if (sign < 0) val = -val;
            if (field->decimals > 0) {
                double scale = 1.0;
                uint8_t di;
                for (di = 0; di < field->decimals; di++) scale *= 10.0;
                ZVAL_DOUBLE(rv, (double)val / scale);
            } else {
                ZVAL_LONG(rv, (zend_long)val);
            }
            break;
        }

        default:
            ZVAL_NULL(rv);
    }

    return rv;
}

static void phopol_encode_field(uint8_t *data, phopol_field_t *field, zval *value)
{
    uint8_t *ptr = data + field->offset;

    switch (field->type) {

        case PHOPOL_TYPE_DISPLAY:
            if (field->digits == 0) {
                zend_string *str = zval_get_string(value);
                memset(ptr, ' ', field->length);
                size_t copy_len = ZSTR_LEN(str) < field->length
                                ? ZSTR_LEN(str) : field->length;
                memcpy(ptr, ZSTR_VAL(str), copy_len);
                zend_string_release(str);
            } else {
                char buf[64];
                zend_long lval = zval_get_long(value);
                int written = snprintf(buf, sizeof(buf), "%lld", (long long)lval);
                if (written <= 0) { memset(ptr, '0', field->length); break; }
                if ((size_t)written >= field->length) {
                    memcpy(ptr, buf + written - field->length, field->length);
                } else {
                    memset(ptr, '0', field->length);
                    memcpy(ptr + field->length - written, buf, written);
                }
            }
            break;

        case PHOPOL_TYPE_BINARY:
        case PHOPOL_TYPE_NATIVE: {
            zend_long lval = zval_get_long(value);
            switch (field->length) {
                case 2: { int16_t v = (int16_t)lval; memcpy(ptr, &v, 2); break; }
                case 4: { int32_t v = (int32_t)lval; memcpy(ptr, &v, 4); break; }
                case 8: { int64_t v = (int64_t)lval; memcpy(ptr, &v, 8); break; }
            }
            break;
        }

        case PHOPOL_TYPE_FLOAT32: {
            float v = (float)zval_get_double(value);
            memcpy(ptr, &v, 4);
            break;
        }
        case PHOPOL_TYPE_FLOAT64: {
            double v = zval_get_double(value);
            memcpy(ptr, &v, 8);
            break;
        }

        case PHOPOL_TYPE_PACKED: {
            /* BCD: two digits per byte; last nibble = sign (0x0C + / 0x0D -) */
            double  dval = zval_get_double(value);
            int     is_neg = (dval < 0.0);
            uint8_t sign_nibble = is_neg ? 0x0D : 0x0C;
            double  abs_val = is_neg ? -dval : dval;
            double  scale = 1.0;
            uint8_t di;
            for (di = 0; di < field->decimals; di++) scale *= 10.0;
            int64_t scaled = (int64_t)(abs_val * scale + 0.5);
            int     total_nibbles = (int)(field->length * 2);
            int     k;
            memset(ptr, 0, field->length);
            ptr[field->length - 1] = sign_nibble; /* lo nibble of last byte */
            for (k = total_nibbles - 2; k >= 0; k--) {
                int digit = (int)(scaled % 10);
                scaled /= 10;
                if (k % 2 == 0)
                    ptr[k / 2] |= (uint8_t)(digit << 4); /* hi nibble */
                else
                    ptr[k / 2] |= (uint8_t)digit;         /* lo nibble */
            }
            break;
        }
    }
}

/* =======================================================================
 * CLASS: OBJECT LIFECYCLE
 * ===================================================================== */

zend_class_entry            *phopol_level01_ce;
static zend_object_handlers  phopol_level01_handlers;

static zend_object *phopol_level01_create_object(zend_class_entry *ce)
{
    phopol_level01_object *intern =
        zend_object_alloc(sizeof(phopol_level01_object), ce);

    intern->layout    = NULL;   /* set by __construct */
    intern->data      = NULL;
    intern->length    = 0;
    intern->owns_data = 0;
    ZVAL_UNDEF(&intern->base_zval);

    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &phopol_level01_handlers;

    return &intern->std;
}

static void phopol_level01_free_object(zend_object *obj)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);

    if (intern->owns_data && intern->data) {
        efree(intern->data);
        intern->data = NULL;
    }
    if (Z_TYPE(intern->base_zval) == IS_OBJECT) {
        zval_ptr_dtor(&intern->base_zval);
    }

    zend_object_std_dtor(obj);
}

/* =======================================================================
 * REDEFINES helper — always returns the live data pointer.
 * For a redefines overlay, reads base->data dynamically so that
 * any reallocate/attach on the base is immediately visible.
 * ===================================================================== */
static uint8_t *phopol_get_data(phopol_level01_object *intern)
{
    if (Z_TYPE(intern->base_zval) == IS_OBJECT) {
        phopol_level01_object *base =
            phopol_level01_from_obj(Z_OBJ(intern->base_zval));
        return base->data;
    }
    return intern->data;
}

/* =======================================================================
 * CLASS: PROPERTY HANDLERS
 * ===================================================================== */

static zval *phopol_level01_read_property(
    zend_object *obj, zend_string *name, int type, void **cache_slot, zval *rv)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);

    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout — did you call __construct()?");
        ZVAL_NULL(rv); return rv;
    }
    uint8_t *data = phopol_get_data(intern);
    if (!data) {
        zend_throw_error(NULL, "PHoPolLevel01: no buffer — call allocate() or attach() first");
        ZVAL_NULL(rv); return rv;
    }

    phopol_field_t *field =
        phopol_find_field(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name));
    if (!field) {
        zend_throw_error(NULL, "PHoPolLevel01: unknown field \"%s\"", ZSTR_VAL(name));
        ZVAL_NULL(rv); return rv;
    }

    return phopol_decode_field(data, field, rv);
}

static zval *phopol_level01_write_property(
    zend_object *obj, zend_string *name, zval *value, void **cache_slot)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);

    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout — did you call __construct()?");
        return value;
    }
    uint8_t *data = phopol_get_data(intern);
    if (!data) {
        zend_throw_error(NULL, "PHoPolLevel01: no buffer — call allocate() or attach() first");
        return value;
    }

    phopol_field_t *field =
        phopol_find_field(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name));
    if (!field) {
        zend_throw_error(NULL, "PHoPolLevel01: unknown field \"%s\"", ZSTR_VAL(name));
        return value;
    }

    phopol_encode_field(data, field, value);
    return value;
}

static int phopol_level01_has_property(
    zend_object *obj, zend_string *name, int has_set_exists, void **cache_slot)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);
    if (!intern->layout) return 0;
    return phopol_find_field(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name)) ? 1 : 0;
}

/* Expose base_zval to Zend's cycle collector so it can track the reference. */
static HashTable *phopol_level01_get_gc(
    zend_object *obj, zval **table, int *n)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);
    if (Z_TYPE(intern->base_zval) == IS_OBJECT) {
        *table = &intern->base_zval;
        *n     = 1;
    } else {
        *n = 0;
    }
    return zend_std_get_properties(obj);
}

/* =======================================================================
 * CLASS: PHP METHODS
 * ===================================================================== */

ZEND_BEGIN_ARG_INFO_EX(arginfo___construct, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, layoutName, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_METHOD(PHoPolLevel01, __construct)
{
    zend_string *layout_name;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(layout_name)
    ZEND_PARSE_PARAMETERS_END();

    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);

    phopol_layout_t *layout = zend_hash_str_find_ptr(phopol_layouts,
        ZSTR_VAL(layout_name), ZSTR_LEN(layout_name));
    if (!layout) {
        zend_throw_error(NULL,
            "PHoPolLevel01: layout \"%s\" not registered"
            " — call phopol_register_layout() first",
            ZSTR_VAL(layout_name));
        RETURN_THROWS();
    }

    intern->layout = layout;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_allocate, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

PHP_METHOD(PHoPolLevel01, allocate)
{
    ZEND_PARSE_PARAMETERS_NONE();

    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);

    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout — call __construct() first");
        RETURN_THROWS();
    }
    if (intern->owns_data && intern->data) {
        efree(intern->data);
    }

    size_t len     = intern->layout->total_length;
    intern->data      = emalloc(len);
    intern->length    = len;
    intern->owns_data = 1;
    memset(intern->data, ' ', len);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_attach, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, bytes, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_METHOD(PHoPolLevel01, attach)
{
    zend_string *bytes;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(bytes)
    ZEND_PARSE_PARAMETERS_END();

    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);

    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout — call __construct() first");
        RETURN_THROWS();
    }
    if (intern->owns_data && intern->data) {
        efree(intern->data);
    }

    size_t len     = intern->layout->total_length;
    intern->data      = emalloc(len);
    intern->length    = len;
    intern->owns_data = 1;
    memset(intern->data, ' ', len);

    size_t copy_len = ZSTR_LEN(bytes) < len ? ZSTR_LEN(bytes) : len;
    memcpy(intern->data, ZSTR_VAL(bytes), copy_len);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_attachTo, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, base, PHoPolLevel01, 0)
ZEND_END_ARG_INFO()

PHP_METHOD(PHoPolLevel01, attachTo)
{
    zval *base_zv;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(base_zv, phopol_level01_ce)
    ZEND_PARSE_PARAMETERS_END();

    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);
    phopol_level01_object *base   = Z_PHOPOL_LEVEL01_P(base_zv);

    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: call __construct() before attachTo()");
        RETURN_THROWS();
    }
    if (!base->data) {
        zend_throw_error(NULL, "PHoPolLevel01: base record has no buffer — call allocate() first");
        RETURN_THROWS();
    }

    /* Release any previous base reference */
    if (Z_TYPE(intern->base_zval) == IS_OBJECT) {
        zval_ptr_dtor(&intern->base_zval);
    }
    /* Free our own buffer if we owned one */
    if (intern->owns_data && intern->data) {
        efree(intern->data);
        intern->data      = NULL;
        intern->owns_data = 0;
    }

    ZVAL_OBJ_COPY(&intern->base_zval, Z_OBJ_P(base_zv));
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_rawBytes, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_METHOD(PHoPolLevel01, rawBytes)
{
    ZEND_PARSE_PARAMETERS_NONE();
    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);
    uint8_t *data = phopol_get_data(intern);
    if (!data) { RETURN_EMPTY_STRING(); }
    size_t len = Z_TYPE(intern->base_zval) == IS_OBJECT
        ? phopol_level01_from_obj(Z_OBJ(intern->base_zval))->length
        : intern->length;
    RETURN_STRINGL((char *)data, len);
}

static const zend_function_entry phopol_level01_methods[] = {
    PHP_ME(PHoPolLevel01, __construct, arginfo___construct, ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, allocate,    arginfo_allocate,    ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, attach,      arginfo_attach,      ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, attachTo,    arginfo_attachTo,    ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, rawBytes,    arginfo_rawBytes,    ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* =======================================================================
 * GLOBAL FUNCTION: phopol_register_layout()
 *
 * phopol_register_layout(
 *     string $name,
 *     int    $totalLength,
 *     array  $fields          // array of ['name'=>, 'offset'=>, 'length'=>,
 * )                           //           'type'=>, 'digits'=>, 'decimals'=>, 'flags'=>]
 * ===================================================================== */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_register_layout, 0, 3, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, name,        IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, totalLength, IS_LONG,   0)
    ZEND_ARG_TYPE_INFO(0, fields,      IS_ARRAY,  0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(phopol_register_layout)
{
    zend_string *name;
    zend_long    total_length;
    HashTable   *fields_ht;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_STR(name)
        Z_PARAM_LONG(total_length)
        Z_PARAM_ARRAY_HT(fields_ht)
    ZEND_PARSE_PARAMETERS_END();

    /* Already registered — skip (layouts are immutable once set) */
    if (zend_hash_str_find_ptr(phopol_layouts, ZSTR_VAL(name), ZSTR_LEN(name)) != NULL) {
        return;
    }

    uint32_t field_count = zend_hash_num_elements(fields_ht);

    phopol_layout_t *layout = pemalloc(sizeof(phopol_layout_t), 1);
    layout->name         = pestrndup(ZSTR_VAL(name), ZSTR_LEN(name), 1);
    layout->total_length = (size_t)total_length;
    layout->field_count  = field_count;
    layout->fields       = pemalloc(field_count * sizeof(phopol_field_t), 1);

    zval *field_zv;
    uint32_t idx = 0;

    ZEND_HASH_FOREACH_VAL(fields_ht, field_zv) {
        ZVAL_DEREF(field_zv);
        if (Z_TYPE_P(field_zv) != IS_ARRAY) continue;
        HashTable *fht = Z_ARRVAL_P(field_zv);

        zval *zname     = zend_hash_str_find_deref(fht, "name",     4);
        zval *zoffset   = zend_hash_str_find_deref(fht, "offset",   6);
        zval *zlength   = zend_hash_str_find_deref(fht, "length",   6);
        zval *ztype     = zend_hash_str_find_deref(fht, "type",     4);
        zval *zdigits   = zend_hash_str_find_deref(fht, "digits",   6);
        zval *zdecimals = zend_hash_str_find_deref(fht, "decimals", 8);
        zval *zflags    = zend_hash_str_find_deref(fht, "flags",    5);

        if (!zname) continue;

        phopol_field_t *f = &layout->fields[idx++];
        zend_string *fname = zval_get_string(zname);
        f->name     = pestrndup(ZSTR_VAL(fname), ZSTR_LEN(fname), 1);
        zend_string_release(fname);
        f->offset   = (size_t)  (zoffset   ? zval_get_long(zoffset)   : 0);
        f->length   = (size_t)  (zlength   ? zval_get_long(zlength)   : 0);
        f->type     = (phopol_type_t)(ztype ? zval_get_long(ztype)    : 0);
        f->digits   = (uint8_t) (zdigits   ? zval_get_long(zdigits)   : 0);
        f->decimals = (uint8_t) (zdecimals ? zval_get_long(zdecimals) : 0);
        f->flags    = (uint8_t) (zflags    ? zval_get_long(zflags)    : 0);
    } ZEND_HASH_FOREACH_END();

    layout->field_count = idx;

    /* Use str variant so the hash table creates a persistent key copy,
     * not a reference to the request-scoped argument zend_string. */
    zend_hash_str_add_ptr(phopol_layouts, ZSTR_VAL(name), ZSTR_LEN(name), layout);
}

static const zend_function_entry phopol_functions[] = {
    PHP_FE(phopol_register_layout, arginfo_register_layout)
    PHP_FE_END
};

/* =======================================================================
 * MODULE HOUSEKEEPING
 * ===================================================================== */

PHP_MINIT_FUNCTION(phopol)
{
    /* Layout registry */
    phopol_layouts = pemalloc(sizeof(HashTable), 1);
    zend_hash_init(phopol_layouts, 16, NULL, phopol_layout_dtor, 1);

    /* Type constants */
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_DISPLAY", PHOPOL_TYPE_DISPLAY, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_BINARY",  PHOPOL_TYPE_BINARY,  CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_PACKED",  PHOPOL_TYPE_PACKED,  CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_FLOAT32", PHOPOL_TYPE_FLOAT32, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_FLOAT64", PHOPOL_TYPE_FLOAT64, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_NATIVE",  PHOPOL_TYPE_NATIVE,  CONST_CS | CONST_PERSISTENT);

    /* Flag constants */
    REGISTER_LONG_CONSTANT("PHOPOL_FLAG_SIGNED",          PHOPOL_FLAG_SIGNED,          CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_FLAG_SIGN_SEPARATE",   PHOPOL_FLAG_SIGN_SEPARATE,   CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_FLAG_JUSTIFIED_RIGHT", PHOPOL_FLAG_JUSTIFIED_RIGHT, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_FLAG_BLANK_ZERO",      PHOPOL_FLAG_BLANK_ZERO,      CONST_CS | CONST_PERSISTENT);

    /* Register PHoPolLevel01 class */
    zend_class_entry ce;
    INIT_CLASS_ENTRY(ce, "PHoPolLevel01", phopol_level01_methods);
    phopol_level01_ce = zend_register_internal_class(&ce);
    phopol_level01_ce->create_object = phopol_level01_create_object;

    memcpy(&phopol_level01_handlers,
           zend_get_std_object_handlers(),
           sizeof(zend_object_handlers));
    phopol_level01_handlers.offset         = XtOffsetOf(phopol_level01_object, std);
    phopol_level01_handlers.free_obj       = phopol_level01_free_object;
    phopol_level01_handlers.read_property  = phopol_level01_read_property;
    phopol_level01_handlers.write_property = phopol_level01_write_property;
    phopol_level01_handlers.has_property   = phopol_level01_has_property;
    phopol_level01_handlers.get_gc         = phopol_level01_get_gc;

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(phopol)
{
    zend_hash_destroy(phopol_layouts);
    pefree(phopol_layouts, 1);
    phopol_layouts = NULL;
    return SUCCESS;
}

PHP_MINFO_FUNCTION(phopol)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "PHoPol support", "enabled");
    php_info_print_table_row(2, "Version", PHP_PHOPOL_VERSION);
    php_info_print_table_end();
}

zend_module_entry phopol_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_PHOPOL_EXTNAME,
    phopol_functions,
    PHP_MINIT(phopol),
    PHP_MSHUTDOWN(phopol),
    NULL,
    NULL,
    PHP_MINFO(phopol),
    PHP_PHOPOL_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_PHOPOL
ZEND_GET_MODULE(phopol)
#endif
