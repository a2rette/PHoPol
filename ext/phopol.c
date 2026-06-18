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
HashTable *phopol_layouts = NULL;

static void phopol_layout_dtor(zval *zv)
{
    phopol_layout_t *layout = (phopol_layout_t *)Z_PTR_P(zv);
    uint32_t i, j;
    zend_hash_destroy(layout->fields_ht);
    pefree(layout->fields_ht, 1);
    for (i = 0; i < layout->field_count; i++) {
        pefree((void *)layout->fields[i].name, 1);
        if (layout->fields[i].edit_mask)
            pefree((void *)layout->fields[i].edit_mask, 1);
        if (layout->fields[i].depending_on)
            pefree((void *)layout->fields[i].depending_on, 1);
        if (layout->fields[i].initial_value)
            pefree((void *)layout->fields[i].initial_value, 1);
    }
    pefree(layout->fields, 1);
    for (i = 0; i < layout->cond_count; i++) {
        phopol_condition_t *c = &layout->conditions[i];
        pefree((void *)c->name, 1);
        pefree((void *)c->src_field, 1);
        for (j = 0; j < c->value_count; j++) {
            pefree((void *)c->values[j], 1);
        }
        if (c->values) pefree(c->values, 1);
    }
    if (layout->conditions) pefree(layout->conditions, 1);
    pefree((void *)layout->name, 1);
    pefree(layout, 1);
}

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
    ZEND_ARG_TYPE_INFO(0, name,                IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, totalLength,         IS_LONG,   0)
    ZEND_ARG_TYPE_INFO(0, fields,              IS_ARRAY,  0)
    ZEND_ARG_TYPE_INFO(0, conditions,          IS_ARRAY,  1)
    ZEND_ARG_TYPE_INFO(0, decimalPointIsComma, _IS_BOOL,  0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(phopol_register_layout)
{
    zend_string *name;
    zend_long    total_length;
    HashTable   *fields_ht;
    HashTable   *conds_ht = NULL;
    zend_bool    decimal_point_is_comma = 0;

    ZEND_PARSE_PARAMETERS_START(3, 5)
        Z_PARAM_STR(name)
        Z_PARAM_LONG(total_length)
        Z_PARAM_ARRAY_HT(fields_ht)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT_OR_NULL(conds_ht)
        Z_PARAM_BOOL(decimal_point_is_comma)
    ZEND_PARSE_PARAMETERS_END();

    /* Already registered — skip (layouts are immutable once set) */
    if (zend_hash_str_find_ptr(phopol_layouts, ZSTR_VAL(name), ZSTR_LEN(name)) != NULL) {
        return;
    }

    uint32_t field_count = zend_hash_num_elements(fields_ht);

    phopol_layout_t *layout = pemalloc(sizeof(phopol_layout_t), 1);
    layout->name                  = pestrndup(ZSTR_VAL(name), ZSTR_LEN(name), 1);
    layout->total_length          = (size_t)total_length;
    layout->field_count           = field_count;
    layout->fields                = pemalloc(field_count * sizeof(phopol_field_t), 1);
    layout->decimal_point_is_comma = (uint8_t)decimal_point_is_comma;

    zval *field_zv;
    uint32_t idx = 0;

    ZEND_HASH_FOREACH_VAL(fields_ht, field_zv) {
        ZVAL_DEREF(field_zv);
        if (Z_TYPE_P(field_zv) != IS_ARRAY) continue;
        HashTable *fht = Z_ARRVAL_P(field_zv);

        zval *zname      = zend_hash_str_find_deref(fht, "name",       4);
        zval *zoffset    = zend_hash_str_find_deref(fht, "offset",     6);
        zval *zlength    = zend_hash_str_find_deref(fht, "length",     6);
        zval *ztype      = zend_hash_str_find_deref(fht, "type",       4);
        zval *zdigits    = zend_hash_str_find_deref(fht, "digits",     6);
        zval *zdecimals  = zend_hash_str_find_deref(fht, "decimals",   8);
        zval *zflags     = zend_hash_str_find_deref(fht, "flags",      5);
        zval *zedit      = zend_hash_str_find_deref(fht, "editMask",   8);
        zval *zoccursmax   = zend_hash_str_find_deref(fht, "occursMax",    9);
        zval *zentrysize   = zend_hash_str_find_deref(fht, "entrySize",    9);
        zval *zdependingon = zend_hash_str_find_deref(fht, "dependingOn", 11);
        zval *zinitval     = zend_hash_str_find_deref(fht, "initialValue", 12);
        zval *zinitfill    = zend_hash_str_find_deref(fht, "initialIsFill",13);

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
        if (zedit && Z_TYPE_P(zedit) == IS_STRING && ZSTR_LEN(Z_STR_P(zedit)) > 0) {
            zend_string *em = zval_get_string(zedit);
            f->edit_mask = pestrndup(ZSTR_VAL(em), ZSTR_LEN(em), 1);
            zend_string_release(em);
        } else {
            f->edit_mask = NULL;
        }
        {
            zend_long omax = zoccursmax ? zval_get_long(zoccursmax) : 1;
            f->occurs_max = (uint16_t)(omax > 1 ? omax : 1);
        }
        f->entry_size = (size_t)(zentrysize ? zval_get_long(zentrysize) : 0);
        if (zdependingon && Z_TYPE_P(zdependingon) == IS_STRING
                && ZSTR_LEN(Z_STR_P(zdependingon)) > 0) {
            zend_string *ds = zval_get_string(zdependingon);
            f->depending_on = pestrndup(ZSTR_VAL(ds), ZSTR_LEN(ds), 1);
            zend_string_release(ds);
        } else {
            f->depending_on = NULL;
        }
        /* VALUE clause */
        if (zinitval && Z_TYPE_P(zinitval) != IS_NULL) {
            zend_string *ivs = zval_get_string(zinitval);
            f->initial_value     = pestrndup(ZSTR_VAL(ivs), ZSTR_LEN(ivs), 1);
            f->initial_value_len = ZSTR_LEN(ivs);
            zend_string_release(ivs);
        } else {
            f->initial_value     = NULL;
            f->initial_value_len = 0;
        }
        f->initial_is_fill = (zinitfill && zval_is_true(zinitfill)) ? 1 : 0;
    } ZEND_HASH_FOREACH_END();

    layout->field_count = idx;

    /* Build the O(1) field-name lookup table */
    layout->fields_ht = pemalloc(sizeof(HashTable), 1);
    zend_hash_init(layout->fields_ht, layout->field_count, NULL, NULL, 1);
    {
        uint32_t fi;
        for (fi = 0; fi < layout->field_count; fi++) {
            zend_hash_str_add_ptr(layout->fields_ht,
                layout->fields[fi].name, strlen(layout->fields[fi].name),
                &layout->fields[fi]);
        }
    }

    /* --- Parse optional conditions array --- */
    layout->cond_count  = 0;
    layout->conditions  = NULL;

    if (conds_ht && zend_hash_num_elements(conds_ht) > 0) {
        uint32_t cond_count = zend_hash_num_elements(conds_ht);
        layout->conditions = pemalloc(cond_count * sizeof(phopol_condition_t), 1);

        zval *cond_zv;
        uint32_t cidx = 0;

        ZEND_HASH_FOREACH_VAL(conds_ht, cond_zv) {
            ZVAL_DEREF(cond_zv);
            if (Z_TYPE_P(cond_zv) != IS_ARRAY) continue;
            HashTable *cht = Z_ARRVAL_P(cond_zv);

            zval *zname   = zend_hash_str_find_deref(cht, "name",  4);
            zval *zfield  = zend_hash_str_find_deref(cht, "field", 5);
            zval *zvalues = zend_hash_str_find_deref(cht, "values", 6);
            zval *zrange  = zend_hash_str_find_deref(cht, "range",  5);

            if (!zname || !zfield) continue;

            phopol_condition_t *c = &layout->conditions[cidx];
            zend_string *cname  = zval_get_string(zname);
            zend_string *cfield = zval_get_string(zfield);
            c->name      = pestrndup(ZSTR_VAL(cname),  ZSTR_LEN(cname),  1);
            c->src_field = pestrndup(ZSTR_VAL(cfield), ZSTR_LEN(cfield), 1);
            zend_string_release(cname);
            zend_string_release(cfield);
            c->values      = NULL;
            c->value_count = 0;
            c->range_lo    = 0;
            c->range_hi    = 0;

            if (zvalues && Z_TYPE_P(zvalues) == IS_ARRAY) {
                c->kind = PHOPOL_COND_VALUES;
                HashTable *vht = Z_ARRVAL_P(zvalues);
                uint32_t vcnt = zend_hash_num_elements(vht);
                c->values = pemalloc(vcnt * sizeof(const char *), 1);
                zval *vzv;
                uint32_t vidx = 0;
                ZEND_HASH_FOREACH_VAL(vht, vzv) {
                    ZVAL_DEREF(vzv);
                    zend_string *vs = zval_get_string(vzv);
                    c->values[vidx++] = pestrndup(ZSTR_VAL(vs), ZSTR_LEN(vs), 1);
                    zend_string_release(vs);
                } ZEND_HASH_FOREACH_END();
                c->value_count = vidx;
            } else if (zrange && Z_TYPE_P(zrange) == IS_ARRAY) {
                c->kind = PHOPOL_COND_RANGE;
                HashTable *rht = Z_ARRVAL_P(zrange);
                zval *zlo = zend_hash_index_find(rht, 0);
                zval *zhi = zend_hash_index_find(rht, 1);
                c->range_lo = zlo ? (int64_t)zval_get_long(zlo) : 0;
                c->range_hi = zhi ? (int64_t)zval_get_long(zhi) : 0;
            } else {
                /* Malformed entry — free name/field and skip */
                pefree((void *)c->name,      1);
                pefree((void *)c->src_field, 1);
                continue;
            }
            cidx++;
        } ZEND_HASH_FOREACH_END();

        layout->cond_count = cidx;
    }

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
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_NATIVE",     PHOPOL_TYPE_NATIVE,     CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_PHP_LONG",   PHOPOL_TYPE_PHP_LONG,   CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_PHP_DOUBLE", PHOPOL_TYPE_PHP_DOUBLE, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_TYPE_PHP_STRING", PHOPOL_TYPE_PHP_STRING, CONST_CS | CONST_PERSISTENT);

    /* Flag constants */
    REGISTER_LONG_CONSTANT("PHOPOL_FLAG_SIGNED",          PHOPOL_FLAG_SIGNED,          CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_FLAG_SIGN_SEPARATE",   PHOPOL_FLAG_SIGN_SEPARATE,   CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_FLAG_JUSTIFIED_RIGHT", PHOPOL_FLAG_JUSTIFIED_RIGHT, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("PHOPOL_FLAG_BLANK_ZERO",      PHOPOL_FLAG_BLANK_ZERO,      CONST_CS | CONST_PERSISTENT);

    /* Figurative constants — singular and plural forms are equivalent in COBOL */
    REGISTER_STRING_CONSTANT("SPACE",        " ",    CONST_CS | CONST_PERSISTENT);
    REGISTER_STRING_CONSTANT("SPACES",       " ",    CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT(  "ZERO",          0,     CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT(  "ZEROS",         0,     CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT(  "ZEROES",        0,     CONST_CS | CONST_PERSISTENT);
    REGISTER_STRING_CONSTANT("HIGH_VALUE",  "\xFF",  CONST_CS | CONST_PERSISTENT);
    REGISTER_STRING_CONSTANT("HIGH_VALUES", "\xFF",  CONST_CS | CONST_PERSISTENT);
    zend_register_stringl_constant("LOW_VALUE",  sizeof("LOW_VALUE") -1,
        "\x00", 1, CONST_CS | CONST_PERSISTENT, module_number);
    zend_register_stringl_constant("LOW_VALUES", sizeof("LOW_VALUES")-1,
        "\x00", 1, CONST_CS | CONST_PERSISTENT, module_number);
    REGISTER_STRING_CONSTANT("QUOTE",        "\"",   CONST_CS | CONST_PERSISTENT);
    REGISTER_STRING_CONSTANT("QUOTES",       "\"",   CONST_CS | CONST_PERSISTENT);

    /* Register PHoPolLevel01, PHoPolOccursGroup classes, and runtime functions */
    phopol_level_minit();

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(phopol)
{
    /* Free persistent sub-layout cache first (its dtors borrow parent layout strings) */
    phopol_level_mshutdown();
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
