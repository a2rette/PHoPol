#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <math.h>
#include <ctype.h>
#include "php.h"
#include "phopol_codec.h"

/* =======================================================================
 * EDITED PICTURE MASK FORMATTING
 *
 * Port of PHoPolRuntime::editFormat().  Supported mask characters:
 *   9  mandatory digit     Z  zero-suppress digit    *  asterisk fill
 *   $  floating currency   ,  group separator        .  decimal point
 *   V  implied decimal     /  slash insertion        B  blank insertion
 *   +  sign (always)       -  sign (neg only)        CR/DB  suffix
 *
 * Rule for callers: apply only when value is numeric (IS_LONG / IS_DOUBLE).
 * String values bypass the mask and are stored verbatim (MOVE SPACES etc.).
 * ===================================================================== */

void phopol_edit_format(
    uint8_t *ptr, size_t field_len, const char *mask, double value,
    uint8_t decimal_point_is_comma)
{
    int    is_neg  = (value < 0.0);
    double abs_val = is_neg ? -value : value;
    int    i;
    size_t mlen = strlen(mask);

    /* Uppercase copy (bounded) */
    char umask[64];
    if (mlen >= sizeof(umask)) mlen = sizeof(umask) - 1;
    for (i = 0; i < (int)mlen; i++)
        umask[i] = (char)toupper((unsigned char)mask[i]);
    umask[mlen] = '\0';

    /* SPECIAL-NAMES DECIMAL POINT IS COMMA: swap the roles of '.' and ',' */
    char decimal_ch   = decimal_point_is_comma ? ',' : '.';
    char thousands_ch = decimal_point_is_comma ? '.' : ',';

    /* --- Find split position: decimal_ch (printed) or 'V' (virtual) --- */
    int split_at = -1;
    int has_dot  = 0;
    for (i = 0; i < (int)mlen; i++) {
        if (umask[i] == decimal_ch) { split_at = i; has_dot = 1; break; }
        if (umask[i] == 'V')        { split_at = i;              break; }
    }

    int  int_mlen  = (split_at >= 0) ? split_at : (int)mlen;
    char *dec_m    = (split_at >= 0) ? (umask + split_at + 1) : "";
    int  dec_mlen  = (split_at >= 0) ? (int)mlen - split_at - 1 : 0;

    /* --- Strip CR / DB suffix --- */
    int  has_suffix = 0;
    char suf0 = '\0', suf1 = '\0';
    if (dec_mlen >= 2) {
        char e0 = dec_m[dec_mlen - 2], e1 = dec_m[dec_mlen - 1];
        if ((e0 == 'C' && e1 == 'R') || (e0 == 'D' && e1 == 'B')) {
            has_suffix = 1; suf0 = e0; suf1 = e1;
            dec_mlen  -= 2;
        }
    }

    /* --- Count digit slots --- */
    int dec_slots = 0;
    for (i = 0; i < dec_mlen; i++)
        if (dec_m[i] == '9' || dec_m[i] == 'Z') dec_slots++;

    int int_slots = 0;
    for (i = 0; i < int_mlen; i++) {
        char c = umask[i];
        if (c == '9' || c == 'Z' || c == '$' || c == '*') int_slots++;
    }
    if (int_slots == 0) int_slots = 1;

    /* --- Build digit strings --- */
    char int_str[32];
    char dec_str[32];

    if (dec_slots > 0) {
        char fmt_buf[64];
        snprintf(fmt_buf, sizeof(fmt_buf), "%.*f", dec_slots, abs_val);
        char *dot_pos = strchr(fmt_buf, '.');
        if (dot_pos) {
            int ilen = (int)(dot_pos - fmt_buf);
            if (ilen >= int_slots) {
                memcpy(int_str, fmt_buf + ilen - int_slots, int_slots);
            } else {
                int pad = int_slots - ilen;
                memset(int_str, '0', pad);
                memcpy(int_str + pad, fmt_buf, ilen);
            }
            int_str[int_slots] = '\0';
            char *d = dot_pos + 1;
            int   dlen = (int)strlen(d);
            if (dlen >= dec_slots) {
                memcpy(dec_str, d, dec_slots);
            } else {
                memcpy(dec_str, d, dlen);
                memset(dec_str + dlen, '0', dec_slots - dlen);
            }
            dec_str[dec_slots] = '\0';
        }
    } else {
        int64_t ival = (int64_t)(abs_val + 0.5);
        char fmt_buf[32];
        snprintf(fmt_buf, sizeof(fmt_buf), "%lld", (long long)ival);
        int ilen = (int)strlen(fmt_buf);
        if (ilen >= int_slots) {
            memcpy(int_str, fmt_buf + ilen - int_slots, int_slots);
        } else {
            int pad = int_slots - ilen;
            memset(int_str, '0', pad);
            memcpy(int_str + pad, fmt_buf, ilen);
        }
        int_str[int_slots] = '\0';
        dec_str[0] = '\0';
    }

    /* --- First significant digit index ---
     * Default: int_slots (beyond last) so that pure-Z masks suppress all
     * digits when value is zero.  '9' outputs unconditionally regardless. */
    int first_sig = int_slots;
    for (i = 0; i < int_slots; i++) {
        if (int_str[i] != '0') { first_sig = i; break; }
    }

    /* --- Walk integer mask, build output --- */
    char out[128];
    int  out_pos    = 0;
    int  digit_idx  = 0;
    int  suppress   = 1;
    int  float_done = 0;

    for (i = 0; i < int_mlen && out_pos < (int)sizeof(out) - 4; i++) {
        char ch = umask[i];
        switch (ch) {
            case '9':
                out[out_pos++] = (digit_idx < int_slots) ? int_str[digit_idx++] : '0';
                suppress = 0;
                break;

            case 'Z':
                if (suppress && digit_idx < first_sig) {
                    out[out_pos++] = ' ';
                } else {
                    out[out_pos++] = (digit_idx < int_slots) ? int_str[digit_idx] : '0';
                    suppress = 0;
                }
                digit_idx++;
                break;

            case '$':
                if (suppress && digit_idx < first_sig - 1) {
                    out[out_pos++] = ' ';
                } else if (suppress && digit_idx == first_sig - 1 && !float_done) {
                    out[out_pos++] = '$';
                    float_done = 1;
                    suppress   = 0;
                } else {
                    out[out_pos++] = (digit_idx < int_slots) ? int_str[digit_idx] : '0';
                    suppress = 0;
                }
                digit_idx++;
                break;

            case '*':
                if (suppress && digit_idx < first_sig) {
                    out[out_pos++] = '*';
                } else {
                    out[out_pos++] = (digit_idx < int_slots) ? int_str[digit_idx] : '0';
                    suppress = 0;
                }
                digit_idx++;
                break;

            case ',':
            case '.':
                /* Either char can be the thousands inserter depending on locale */
                if (umask[i] == thousands_ch) {
                    out[out_pos++] = suppress ? ' ' : thousands_ch;
                } else {
                    out[out_pos++] = mask[i]; /* decimal sep in intMask: passthrough */
                }
                break;

            case '+':
                out[out_pos++] = is_neg ? '-' : '+';
                break;

            case '-':
                out[out_pos++] = is_neg ? '-' : ' ';
                break;

            case 'B':
                out[out_pos++] = ' ';
                break;

            default:
                /* '/' and other insertion characters (date slashes, etc.) */
                out[out_pos++] = mask[i];
                break;
        }
    }

    /* --- Decimal part --- */
    if (split_at >= 0 && dec_mlen > 0) {
        if (has_dot && out_pos < (int)sizeof(out) - 1) out[out_pos++] = decimal_ch;
        int didx = 0;
        /* Use original mask for decimal insertion chars to preserve case */
        const char *orig_dec = mask + split_at + 1;
        for (i = 0; i < dec_mlen && out_pos < (int)sizeof(out) - 1; i++) {
            char c = dec_m[i];
            if (c == '9' || c == 'Z') {
                out[out_pos++] = (didx < dec_slots) ? dec_str[didx++] : '0';
            } else {
                out[out_pos++] = orig_dec[i];
            }
        }
    }

    /* --- Suffix (CR / DB) --- */
    if (has_suffix && out_pos < (int)sizeof(out) - 2) {
        if (is_neg) { out[out_pos++] = suf0; out[out_pos++] = suf1; }
        else        { out[out_pos++] = ' ';  out[out_pos++] = ' ';  }
    }

    /* --- Write to field buffer --- */
    memset(ptr, ' ', field_len);
    size_t copy_len = (size_t)out_pos < field_len ? (size_t)out_pos : field_len;
    memcpy(ptr, out, copy_len);
}

/* =======================================================================
 * FIELD ENCODE / DECODE
 * ===================================================================== */

zval *phopol_decode_field(uint8_t *data, phopol_field_t *field, zval *rv)
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
                if (field->flags & PHOPOL_FLAG_SIGNED) {
                    int is_neg = 0;
                    if (field->flags & PHOPOL_FLAG_SIGN_SEPARATE) {
                        /* First byte is '+' or '-'; digits follow */
                        is_neg = (buf[0] == '-');
                        memmove(buf, buf + 1, len - 1);
                        buf[len - 1] = '\0';
                    } else {
                        /* Zoned decimal: sign in high nibble of last byte */
                        uint8_t last = (uint8_t)ptr[len - 1];
                        if ((last & 0xF0) == 0xD0) is_neg = 1;
                        buf[len - 1] = (char)(0x30 | (last & 0x0F));
                    }
                    zend_long val = (zend_long)atoll(buf);
                    ZVAL_LONG(rv, is_neg ? -val : val);
                } else {
                    ZVAL_LONG(rv, (zend_long)atoll(buf));
                }
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

        case PHOPOL_TYPE_PHP_LONG: {
            zend_long v;
            memcpy(&v, ptr, sizeof(zend_long));
            ZVAL_LONG(rv, v);
            break;
        }
        case PHOPOL_TYPE_PHP_DOUBLE: {
            double v;
            memcpy(&v, ptr, sizeof(double));
            ZVAL_DOUBLE(rv, v);
            break;
        }
        case PHOPOL_TYPE_PHP_STRING: {
            zend_string *s;
            memcpy(&s, ptr, sizeof(zend_string *));
            if (s) { ZVAL_STR_COPY(rv, s); } else { ZVAL_EMPTY_STRING(rv); }
            break;
        }

        default:
            ZVAL_NULL(rv);
    }

    return rv;
}

void phopol_encode_field(uint8_t *data, phopol_field_t *field, zval *value,
                         uint8_t decimal_point_is_comma)
{
    uint8_t *ptr = data + field->offset;

    switch (field->type) {

        case PHOPOL_TYPE_DISPLAY:
            /* Edited picture field: apply mask for numeric values */
            if (field->edit_mask && field->edit_mask[0]
                    && (Z_TYPE_P(value) == IS_LONG || Z_TYPE_P(value) == IS_DOUBLE
                        || Z_TYPE_P(value) == IS_NULL)) {
                phopol_edit_format(ptr, field->length, field->edit_mask,
                                   zval_get_double(value), decimal_point_is_comma);
                break;
            }
            if (field->digits == 0) {
                zend_string *str = zval_get_string(value);
                memset(ptr, ' ', field->length);
                size_t copy_len = ZSTR_LEN(str) < field->length
                                ? ZSTR_LEN(str) : field->length;
                memcpy(ptr, ZSTR_VAL(str), copy_len);
                zend_string_release(str);
            } else {
                char      buf[64];
                zend_long lval   = zval_get_long(value);
                int       is_neg = (lval < 0);
                zend_long absval = is_neg ? -lval : lval;
                size_t    dig_off = 0;
                size_t    dig_len = field->length;

                /* SIGN LEADING SEPARATE: write sign byte first, digits after */
                if ((field->flags & PHOPOL_FLAG_SIGNED)
                        && (field->flags & PHOPOL_FLAG_SIGN_SEPARATE)) {
                    ptr[0]  = is_neg ? '-' : '+';
                    dig_off = 1;
                    dig_len = field->length - 1;
                }

                int written = snprintf(buf, sizeof(buf), "%lld", (long long)absval);
                if (written <= 0) {
                    memset(ptr + dig_off, '0', dig_len);
                } else if ((size_t)written >= dig_len) {
                    memcpy(ptr + dig_off, buf + written - dig_len, dig_len);
                } else {
                    memset(ptr + dig_off, '0', dig_len);
                    memcpy(ptr + dig_off + dig_len - written, buf, written);
                }

                /* Zoned decimal: embed sign in high nibble of last digit byte */
                if ((field->flags & PHOPOL_FLAG_SIGNED)
                        && !(field->flags & PHOPOL_FLAG_SIGN_SEPARATE)
                        && is_neg) {
                    ptr[field->length - 1] =
                        (ptr[field->length - 1] & 0x0F) | 0xD0;
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

        case PHOPOL_TYPE_PHP_LONG: {
            zend_long v = zval_get_long(value);
            memcpy(ptr, &v, sizeof(zend_long));
            break;
        }
        case PHOPOL_TYPE_PHP_DOUBLE: {
            double v = zval_get_double(value);
            memcpy(ptr, &v, sizeof(double));
            break;
        }
        case PHOPOL_TYPE_PHP_STRING: {
            zend_string *old_s;
            memcpy(&old_s, ptr, sizeof(zend_string *));
            if (old_s) zend_string_release(old_s);
            zend_string *new_s = zval_get_string(value);
            memcpy(ptr, &new_s, sizeof(zend_string *));
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
 * CONDITION EVALUATION
 *
 * For VALUES kind: decode field, convert to string, right-trim spaces,
 * then compare against each condition value.  This works for both alpha
 * (returns a zend_string) and numeric fields (long → "42" via zval_get_string).
 *
 * For RANGE kind: decode field, get long, check [range_lo, range_hi].
 * ===================================================================== */

int phopol_eval_condition(
    uint8_t *data, phopol_layout_t *layout, phopol_condition_t *cond)
{
    phopol_field_t *src =
        phopol_find_field(layout, cond->src_field, strlen(cond->src_field));
    if (!src) return 0;

    if (cond->kind == PHOPOL_COND_VALUES) {
        zval rv;
        ZVAL_UNDEF(&rv);
        phopol_decode_field(data, src, &rv);
        zend_string *sval = zval_get_string(&rv);
        zval_ptr_dtor(&rv);

        const char *sv   = ZSTR_VAL(sval);
        size_t      slen = ZSTR_LEN(sval);
        while (slen > 0 && sv[slen - 1] == ' ') slen--;

        int match = 0;
        uint32_t i;
        for (i = 0; i < cond->value_count && !match; i++) {
            size_t vlen = strlen(cond->values[i]);
            if (vlen == slen && memcmp(cond->values[i], sv, slen) == 0) {
                match = 1;
            }
        }
        zend_string_release(sval);
        return match;

    } else { /* PHOPOL_COND_RANGE */
        zval rv;
        ZVAL_UNDEF(&rv);
        phopol_decode_field(data, src, &rv);
        zend_long lval = zval_get_long(&rv);
        zval_ptr_dtor(&rv);
        return (lval >= (zend_long)cond->range_lo &&
                lval <= (zend_long)cond->range_hi);
    }
}
