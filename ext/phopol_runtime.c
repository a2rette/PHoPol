#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_phopol.h"
#include "phopol_runtime.h"
#include "phopol_codec.h"

/* =======================================================================
 * INITIALIZE — COBOL INITIALIZE verb
 *
 * Resets every elementary item in the layout to its category default:
 *   alphanumeric / alphabetic display  → SPACES
 *   numeric display                    → ZEROS ("00000")
 *   numeric-edited (edit_mask set)     → ZERO through the picture mask
 *   binary / native / float            → zero bytes
 *   packed decimal                     → BCD positive zero (0x0C sign nibble)
 *
 * OCCURS fields: loops over all occurs_max cells stepping by entry_size.
 * ===================================================================== */

PHP_METHOD(PHoPolLevel01, initialize)
{
    ZEND_PARSE_PARAMETERS_NONE();

    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);
    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout — call __construct() first");
        RETURN_THROWS();
    }
    uint8_t *data = phopol_get_data(intern);
    if (!data) {
        zend_throw_error(NULL, "PHoPolLevel01: no buffer — call allocate() or attach() first");
        RETURN_THROWS();
    }

    phopol_initialize(data, intern->layout);
}

void phopol_initialize(uint8_t *data, phopol_layout_t *layout)
{
    uint32_t i, c;

    for (i = 0; i < layout->field_count; i++) {
        phopol_field_t *f = &layout->fields[i];

        uint32_t cell_count = (f->occurs_max > 1) ? (uint32_t)f->occurs_max : 1;
        size_t   step       = (f->entry_size  > 0) ? f->entry_size           : 0;

        for (c = 0; c < cell_count; c++) {
            uint8_t *ptr = data + f->offset + (size_t)c * step;

            switch (f->type) {

                case PHOPOL_TYPE_DISPLAY:
                    if (f->digits == 0 && (!f->edit_mask || !f->edit_mask[0])) {
                        memset(ptr, ' ', f->length);
                    } else {
                        /* numeric display or numeric-edited → ZERO
                         * (ptr - f->offset) + f->offset == ptr  */
                        zval zero;
                        ZVAL_LONG(&zero, 0);
                        phopol_encode_field(ptr - f->offset, f, &zero,
                                            layout->decimal_point_is_comma);
                    }
                    break;

                case PHOPOL_TYPE_BINARY:
                case PHOPOL_TYPE_NATIVE:
                case PHOPOL_TYPE_FLOAT32:
                case PHOPOL_TYPE_FLOAT64:
                    memset(ptr, 0, f->length);
                    break;

                case PHOPOL_TYPE_PACKED:
                    memset(ptr, 0, f->length);
                    if (f->length > 0) ptr[f->length - 1] = 0x0C;  /* BCD +0 */
                    break;
            }
        }
    }
}

/* =======================================================================
 * GLOBAL VERB IMPLEMENTATIONS
 * ===================================================================== */

/* -----------------------------------------------------------------------
 * move_corresponding($src, $dst)
 * Copies fields with matching names from $src into $dst.
 * COBOL: MOVE CORRESPONDING src TO dst
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(move_corresponding)
{
    zval *src_zv, *dst_zv;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(src_zv, phopol_level01_ce)
        Z_PARAM_OBJECT_OF_CLASS(dst_zv, phopol_level01_ce)
    ZEND_PARSE_PARAMETERS_END();

    phopol_level01_object *src_o = Z_PHOPOL_LEVEL01_P(src_zv);
    phopol_level01_object *dst_o = Z_PHOPOL_LEVEL01_P(dst_zv);

    if (!src_o->layout || !dst_o->layout) {
        zend_throw_error(NULL, "move_corresponding: both records must have a layout");
        RETURN_THROWS();
    }
    uint8_t *src_data = phopol_get_data(src_o);
    uint8_t *dst_data = phopol_get_data(dst_o);
    if (!src_data || !dst_data) {
        zend_throw_error(NULL, "move_corresponding: both records must have a buffer");
        RETURN_THROWS();
    }

    uint32_t i;
    for (i = 0; i < src_o->layout->field_count; i++) {
        phopol_field_t *sf = &src_o->layout->fields[i];
        if (sf->occurs_max > 1) continue;
        phopol_field_t *df = phopol_find_field(dst_o->layout, sf->name, strlen(sf->name));
        if (!df || df->occurs_max > 1) continue;

        zval rv;
        ZVAL_UNDEF(&rv);
        phopol_decode_field(src_data, sf, &rv);
        phopol_encode_field(dst_data, df, &rv, dst_o->layout->decimal_point_is_comma);
        zval_ptr_dtor(&rv);
    }
}

/* -----------------------------------------------------------------------
 * add_corresponding($src, $dst)
 * Adds numeric fields with matching names from $src into $dst.
 * COBOL: ADD CORRESPONDING src TO dst
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(add_corresponding)
{
    zval *src_zv, *dst_zv;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(src_zv, phopol_level01_ce)
        Z_PARAM_OBJECT_OF_CLASS(dst_zv, phopol_level01_ce)
    ZEND_PARSE_PARAMETERS_END();

    phopol_level01_object *src_o = Z_PHOPOL_LEVEL01_P(src_zv);
    phopol_level01_object *dst_o = Z_PHOPOL_LEVEL01_P(dst_zv);

    if (!src_o->layout || !dst_o->layout) {
        zend_throw_error(NULL, "add_corresponding: both records must have a layout");
        RETURN_THROWS();
    }
    uint8_t *src_data = phopol_get_data(src_o);
    uint8_t *dst_data = phopol_get_data(dst_o);
    if (!src_data || !dst_data) {
        zend_throw_error(NULL, "add_corresponding: both records must have a buffer");
        RETURN_THROWS();
    }

    uint32_t i;
    for (i = 0; i < src_o->layout->field_count; i++) {
        phopol_field_t *sf = &src_o->layout->fields[i];
        if (sf->occurs_max > 1) continue;
        /* Skip pure alpha fields */
        if (sf->type == PHOPOL_TYPE_DISPLAY && sf->digits == 0) continue;

        phopol_field_t *df = phopol_find_field(dst_o->layout, sf->name, strlen(sf->name));
        if (!df || df->occurs_max > 1) continue;
        if (df->type == PHOPOL_TYPE_DISPLAY && df->digits == 0) continue;

        zval src_rv, dst_rv;
        ZVAL_UNDEF(&src_rv);
        ZVAL_UNDEF(&dst_rv);
        phopol_decode_field(src_data, sf, &src_rv);
        phopol_decode_field(dst_data, df, &dst_rv);

        double sum = zval_get_double(&src_rv) + zval_get_double(&dst_rv);
        zval_ptr_dtor(&src_rv);
        zval_ptr_dtor(&dst_rv);

        zval sum_zv;
        ZVAL_DOUBLE(&sum_zv, sum);
        phopol_encode_field(dst_data, df, &sum_zv, dst_o->layout->decimal_point_is_comma);
    }
}

/* -----------------------------------------------------------------------
 * search_all($level, $tablePrefix, $keySubField, $searchValue): ?int
 * Binary search on an OCCURS table ordered ascending on $keySubField.
 * Returns 1-based index of matching entry, or null (AT END).
 * COBOL: SEARCH ALL table WHEN key = value
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(search_all)
{
    zval        *level_zv;
    zend_string *table_prefix, *key_subfield;
    zval        *search_value;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_OBJECT_OF_CLASS(level_zv, phopol_level01_ce)
        Z_PARAM_STR(table_prefix)
        Z_PARAM_STR(key_subfield)
        Z_PARAM_ZVAL(search_value)
    ZEND_PARSE_PARAMETERS_END();

    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(level_zv);
    if (!intern->layout) {
        zend_throw_error(NULL, "search_all: record has no layout");
        RETURN_THROWS();
    }
    uint8_t *data = phopol_get_data(intern);
    if (!data) {
        zend_throw_error(NULL, "search_all: record has no buffer");
        RETURN_THROWS();
    }

    /* Build "tablePrefix[*]_keySubField" to locate the key field descriptor */
    char key_name[256];
    int  key_name_len = snprintf(key_name, sizeof(key_name), "%s[*]_%s",
                                 ZSTR_VAL(table_prefix), ZSTR_VAL(key_subfield));
    phopol_field_t *kf = phopol_find_field(intern->layout, key_name, (size_t)key_name_len);
    if (!kf) {
        zend_throw_error(NULL,
            "search_all: table '%s' with key '%s' not found in layout",
            ZSTR_VAL(table_prefix), ZSTR_VAL(key_subfield));
        RETURN_THROWS();
    }

    zend_long lo = 1;
    zend_long hi = (zend_long)kf->occurs_max;

    while (lo <= hi) {
        zend_long mid = (lo + hi) / 2;
        /* Shift base so phopol_decode_field reads the correct cell:
         * (data + (mid-1)*entry_size) + kf->offset  == data + kf->offset + (mid-1)*entry_size */
        uint8_t *shifted = data + (size_t)(mid - 1) * kf->entry_size;
        zval cell_val;
        ZVAL_UNDEF(&cell_val);
        phopol_decode_field(shifted, kf, &cell_val);

        int cmp;
        if (Z_TYPE(cell_val) == IS_STRING && Z_TYPE_P(search_value) == IS_STRING) {
            cmp = zend_binary_strcmp(Z_STRVAL(cell_val), Z_STRLEN(cell_val),
                                     Z_STRVAL_P(search_value), Z_STRLEN_P(search_value));
        } else {
            double cv = zval_get_double(&cell_val);
            double sv = zval_get_double(search_value);
            cmp = (cv < sv) ? -1 : (cv > sv) ? 1 : 0;
        }
        zval_ptr_dtor(&cell_val);

        if (cmp == 0)      { RETURN_LONG(mid); }
        else if (cmp < 0)  { lo = mid + 1; }
        else               { hi = mid - 1; }
    }

    RETURN_NULL();  /* AT END */
}

/* -----------------------------------------------------------------------
 * inspect_tally($value, $target): int
 * Count all occurrences of $target in $value.
 * COBOL: INSPECT field TALLYING tally FOR ALL target
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(inspect_tally)
{
    zend_string *value, *target;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(value)
        Z_PARAM_STR(target)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(target) == 0) { RETURN_LONG(0); }

    zend_long   count    = 0;
    const char *p        = ZSTR_VAL(value);
    size_t      vlen     = ZSTR_LEN(value);
    const char *t        = ZSTR_VAL(target);
    size_t      tlen     = ZSTR_LEN(target);

    while (vlen >= tlen) {
        if (memcmp(p, t, tlen) == 0) { count++; p += tlen; vlen -= tlen; }
        else                          {          p++;       vlen--;       }
    }
    RETURN_LONG(count);
}

/* -----------------------------------------------------------------------
 * inspect_tally_leading($value, $target): int
 * Count leading occurrences of $target in $value.
 * COBOL: INSPECT field TALLYING tally FOR LEADING target
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(inspect_tally_leading)
{
    zend_string *value, *target;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(value)
        Z_PARAM_STR(target)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(target) == 0) { RETURN_LONG(0); }

    zend_long   count = 0;
    const char *p     = ZSTR_VAL(value);
    size_t      vlen  = ZSTR_LEN(value);
    const char *t     = ZSTR_VAL(target);
    size_t      tlen  = ZSTR_LEN(target);

    while (vlen >= tlen && memcmp(p, t, tlen) == 0) {
        count++;
        p    += tlen;
        vlen -= tlen;
    }
    RETURN_LONG(count);
}

/* -----------------------------------------------------------------------
 * inspect_replace($value, $from, $to): string
 * Replace all occurrences of $from with $to in $value.
 * COBOL: INSPECT field REPLACING ALL from BY to
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(inspect_replace)
{
    zend_string *value, *from, *to;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_STR(value)
        Z_PARAM_STR(from)
        Z_PARAM_STR(to)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(from) == 0) { RETURN_STR_COPY(value); }

    const char *f    = ZSTR_VAL(from);
    size_t      flen = ZSTR_LEN(from);
    const char *t    = ZSTR_VAL(to);
    size_t      tlen = ZSTR_LEN(to);
    const char *src  = ZSTR_VAL(value);
    size_t      vlen = ZSTR_LEN(value);

    /* Count occurrences to determine output buffer size */
    size_t count = 0;
    {
        const char *q = src;
        size_t      rem = vlen;
        while (rem >= flen) {
            if (memcmp(q, f, flen) == 0) { count++; q += flen; rem -= flen; }
            else                          {          q++;       rem--;       }
        }
    }
    if (count == 0) { RETURN_STR_COPY(value); }

    size_t       out_len = vlen - count * flen + count * tlen;
    zend_string *result  = zend_string_alloc(out_len, 0);
    char        *dst     = ZSTR_VAL(result);
    size_t       rem     = vlen;

    while (rem > 0) {
        if (rem >= flen && memcmp(src, f, flen) == 0) {
            memcpy(dst, t, tlen); dst += tlen; src += flen; rem -= flen;
        } else {
            *dst++ = *src++; rem--;
        }
    }
    ZSTR_VAL(result)[out_len] = '\0';
    RETURN_STR(result);
}

/* -----------------------------------------------------------------------
 * inspect_convert($value, $fromChars, $toChars): string
 * Character-by-character translation (like PHP strtr with two strings).
 * COBOL: INSPECT field CONVERTING fromChars TO toChars
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(inspect_convert)
{
    zend_string *value, *from_chars, *to_chars;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_STR(value)
        Z_PARAM_STR(from_chars)
        Z_PARAM_STR(to_chars)
    ZEND_PARSE_PARAMETERS_END();

    size_t min_len = ZSTR_LEN(from_chars) < ZSTR_LEN(to_chars)
                   ? ZSTR_LEN(from_chars) : ZSTR_LEN(to_chars);
    if (min_len == 0) { RETURN_STR_COPY(value); }

    zend_string *result = zend_string_dup(value, 0);
    char        *out    = ZSTR_VAL(result);
    size_t       vlen   = ZSTR_LEN(result);
    const char  *fc     = ZSTR_VAL(from_chars);
    const char  *tc     = ZSTR_VAL(to_chars);
    size_t       i, j;

    for (i = 0; i < vlen; i++) {
        for (j = 0; j < min_len; j++) {
            if (out[i] == fc[j]) { out[i] = tc[j]; break; }
        }
    }
    RETURN_STR(result);
}

/* -----------------------------------------------------------------------
 * edit_format($mask, $value [, $decimalPointIsComma]): string
 * Apply a COBOL edited picture mask to a numeric value.
 * COBOL: MOVE value TO edited-field
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(edit_format)
{
    zend_string *mask;
    zval        *value;
    zend_bool    decimal_point_is_comma = 0;
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(mask)
        Z_PARAM_ZVAL(value)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(decimal_point_is_comma)
    ZEND_PARSE_PARAMETERS_END();

    size_t       out_len = ZSTR_LEN(mask);
    zend_string *result  = zend_string_alloc(out_len, 0);

    phopol_edit_format((uint8_t *)ZSTR_VAL(result), out_len, ZSTR_VAL(mask),
                       zval_get_double(value), (uint8_t)decimal_point_is_comma);

    ZSTR_VAL(result)[out_len] = '\0';
    RETURN_STR(result);
}

/* -----------------------------------------------------------------------
 * initialize($level, ...): void
 * COBOL INITIALIZE verb — accepts one or more PHoPolLevel01 objects.
 * COBOL: INITIALIZE level-a level-b
 * ----------------------------------------------------------------------- */
PHP_FUNCTION(phopol_initialize_fn)
{
    zval    *args;
    uint32_t argc;
    ZEND_PARSE_PARAMETERS_START(1, -1)
        Z_PARAM_VARIADIC('+', args, argc)
    ZEND_PARSE_PARAMETERS_END();

    uint32_t i;
    for (i = 0; i < argc; i++) {
        zval *arg = &args[i];
        if (Z_TYPE_P(arg) != IS_OBJECT
                || !instanceof_function(Z_OBJCE_P(arg), phopol_level01_ce)) {
            zend_throw_error(NULL,
                "initialize: argument %u must be a PHoPolLevel01 instance", i + 1);
            return;
        }
        phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(arg);
        if (!intern->layout) {
            zend_throw_error(NULL, "initialize: argument %u has no layout", i + 1);
            return;
        }
        uint8_t *data = phopol_get_data(intern);
        if (!data) {
            zend_throw_error(NULL, "initialize: argument %u has no buffer", i + 1);
            return;
        }
        phopol_initialize(data, intern->layout);
    }
}

/* =======================================================================
 * VERB ARGINFO + METHOD TABLE
 * Registered onto PHoPolLevel01 by phopol_runtime_minit() after class setup.
 * Table placed after all implementations — no forward declarations needed.
 * ===================================================================== */

/* --- Method arginfo (initialize method on PHoPolLevel01) --- */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_initialize, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry phopol_runtime_methods[] = {
    PHP_ME(PHoPolLevel01, initialize, arginfo_initialize, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* --- Global function arginfos --- */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_move_corresponding, 0, 2, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, src, PHoPolLevel01, 0)
    ZEND_ARG_OBJ_INFO(0, dst, PHoPolLevel01, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_add_corresponding, 0, 2, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, src, PHoPolLevel01, 0)
    ZEND_ARG_OBJ_INFO(0, dst, PHoPolLevel01, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_search_all, 0, 4, IS_LONG, 1)
    ZEND_ARG_OBJ_INFO(0, level,       PHoPolLevel01, 0)
    ZEND_ARG_TYPE_INFO(0, tablePrefix, IS_STRING,    0)
    ZEND_ARG_TYPE_INFO(0, keySubField, IS_STRING,    0)
    ZEND_ARG_INFO(0, searchValue)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_inspect_tally, 0, 2, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, value,  IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, target, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_inspect_tally_leading, 0, 2, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, value,  IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, target, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_inspect_replace, 0, 3, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, from,  IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, to,    IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_inspect_convert, 0, 3, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, value,     IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, fromChars, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, toChars,   IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_edit_format, 0, 2, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, mask,               IS_STRING, 0)
    ZEND_ARG_INFO(0, value)
    ZEND_ARG_TYPE_INFO(0, decimalPointIsComma, _IS_BOOL,  0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_phopol_initialize_fn, 0, 1, IS_VOID, 0)
    ZEND_ARG_VARIADIC_OBJ_INFO(0, levels, PHoPolLevel01, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry phopol_runtime_global_functions[] = {
    PHP_FE(move_corresponding,    arginfo_move_corresponding)
    PHP_FE(add_corresponding,     arginfo_add_corresponding)
    PHP_FE(search_all,            arginfo_search_all)
    PHP_FE(inspect_tally,         arginfo_inspect_tally)
    PHP_FE(inspect_tally_leading, arginfo_inspect_tally_leading)
    PHP_FE(inspect_replace,       arginfo_inspect_replace)
    PHP_FE(inspect_convert,       arginfo_inspect_convert)
    PHP_FE(edit_format,           arginfo_edit_format)
    PHP_FALIAS(initialize, phopol_initialize_fn, arginfo_phopol_initialize_fn)
    PHP_FE_END
};

void phopol_runtime_minit(void)
{
    /* Bolt verb methods onto PHoPolLevel01 */
    zend_register_functions(phopol_level01_ce, phopol_runtime_methods,
                            &phopol_level01_ce->function_table, MODULE_PERSISTENT);

    /* Register global runtime functions */
    zend_register_functions(NULL, phopol_runtime_global_functions, NULL, MODULE_PERSISTENT);
}
