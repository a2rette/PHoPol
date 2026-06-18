#ifndef PHOPOL_LAYOUT_H
#define PHOPOL_LAYOUT_H

#include <stdint.h>
#include <stddef.h>
#include <string.h>

/* -----------------------------------------------------------------------
 * Storage types — mirrors FieldType enum in PHoPolField.php
 * --------------------------------------------------------------------- */
typedef enum _phopol_type {
    PHOPOL_TYPE_DISPLAY    = 0,  /* PIC X / PIC 9  — alpha or zoned decimal */
    PHOPOL_TYPE_BINARY     = 1,  /* USAGE BINARY / COMP — two's complement  */
    PHOPOL_TYPE_PACKED     = 2,  /* USAGE COMP-3  — packed decimal (BCD)    */
    PHOPOL_TYPE_FLOAT32    = 3,  /* USAGE COMP-1  — IEEE 754 single (4 B)   */
    PHOPOL_TYPE_FLOAT64    = 4,  /* USAGE COMP-2  — IEEE 754 double (8 B)   */
    PHOPOL_TYPE_NATIVE     = 5,  /* USAGE COMP-5  — machine-native integer  */
    PHOPOL_TYPE_PHP_LONG   = 6,  /* php int   — zend_long stored directly   */
    PHOPOL_TYPE_PHP_DOUBLE = 7,  /* php float — double stored directly      */
    PHOPOL_TYPE_PHP_STRING = 8,  /* php string — zend_string * stored       */
} phopol_type_t;

/* -----------------------------------------------------------------------
 * Field flags — bitmask stored in phopol_field_t.flags
 * --------------------------------------------------------------------- */
#define PHOPOL_FLAG_SIGNED          (1u << 0)  /* signed numeric           */
#define PHOPOL_FLAG_SIGN_SEPARATE   (1u << 1)  /* SIGN LEADING SEPARATE    */
#define PHOPOL_FLAG_JUSTIFIED_RIGHT (1u << 2)  /* JUSTIFIED RIGHT          */
#define PHOPOL_FLAG_BLANK_ZERO      (1u << 3)  /* BLANK WHEN ZERO          */

/* -----------------------------------------------------------------------
 * phopol_field_t — compile-time descriptor for one elementary field
 *
 * All values are fixed at parse time and never change at runtime.
 * read_property / write_property only do pointer arithmetic on these.
 * --------------------------------------------------------------------- */
typedef struct _phopol_field {
    const char    *name;        /* field name (interned — never freed)      */
    size_t         offset;      /* byte offset of field in 1st cell         */
    size_t         length;      /* total byte length of this field          */
    phopol_type_t  type;        /* storage type                             */
    uint8_t        digits;      /* total digit count  (0 for alpha/string)  */
    uint8_t        decimals;    /* decimal places  (m in V9(m))             */
    uint8_t        flags;       /* PHOPOL_FLAG_* bitmask                    */
    const char    *edit_mask;    /* COBOL edited picture mask, NULL if none  */
    const char    *depending_on; /* ODO: field name that gives logical max  */
    uint16_t       occurs_max;   /* number of cells (1 = not an OCCURS field)*/
    size_t         entry_size;   /* bytes per cell  (0 if not OCCURS)        */
    /* VALUE clause — applied once in allocate(), never by INITIALIZE.
     * initial_is_fill=1 → memset each cell's field bytes with initial_value[0]
     *                      (SPACES, HIGH_VALUES, LOW_VALUES)
     * initial_is_fill=0 → encode initial_value through the normal codec
     *                      (string/numeric literals, ZERO)                  */
    const char    *initial_value;     /* NULL = no VALUE clause               */
    size_t         initial_value_len;
    uint8_t        initial_is_fill;
} phopol_field_t;

/* -----------------------------------------------------------------------
 * phopol_condition_t — 88-level condition name descriptor
 *
 * kind == PHOPOL_COND_VALUES: true when src field value == one of values[]
 * kind == PHOPOL_COND_RANGE:  true when src field value in [range_lo..range_hi]
 *
 * SET TO TRUE: write values[0] (VALUES) or range_lo (RANGE) to src field.
 * --------------------------------------------------------------------- */
#define PHOPOL_COND_VALUES 0
#define PHOPOL_COND_RANGE  1

typedef struct _phopol_condition {
    const char  *name;         /* condition name, e.g. "isTerminated"     */
    const char  *src_field;    /* source field name, e.g. "employeeStatus" */
    uint8_t      kind;         /* PHOPOL_COND_VALUES or PHOPOL_COND_RANGE  */
    uint32_t     value_count;  /* number of entries in values[]             */
    const char **values;       /* string values (kind=VALUES)               */
    int64_t      range_lo;     /* inclusive low bound  (kind=RANGE)         */
    int64_t      range_hi;     /* inclusive high bound (kind=RANGE)         */
} phopol_condition_t;

/* -----------------------------------------------------------------------
 * phopol_layout_t — compile-time descriptor for one level(01) level
 *
 * Requires php.h (for HashTable) to be included before phopol_layout.h.
 * --------------------------------------------------------------------- */
typedef struct _phopol_layout {
    const char         *name;
    size_t              total_length;
    uint32_t            field_count;
    phopol_field_t     *fields;
    HashTable          *fields_ht;  /* name → phopol_field_t *; O(1) lookup */
    uint32_t            cond_count;
    phopol_condition_t *conditions;
    uint8_t             decimal_point_is_comma; /* SPECIAL-NAMES DECIMAL POINT IS COMMA */
} phopol_layout_t;

/* -----------------------------------------------------------------------
 * phopol_find_field — O(1) hash lookup via layout->fields_ht.
 * fields_ht is built at layout registration time and lives for the
 * process lifetime (persistent HashTable, NULL dtor).
 * --------------------------------------------------------------------- */
static inline phopol_field_t *phopol_find_field(
    phopol_layout_t *layout, const char *name, size_t name_len)
{
    return (phopol_field_t *)zend_hash_str_find_ptr(layout->fields_ht, name, name_len);
}

/* -----------------------------------------------------------------------
 * phopol_find_condition — linear scan by condition name.
 * --------------------------------------------------------------------- */
static inline phopol_condition_t *phopol_find_condition(
    phopol_layout_t *layout, const char *name, size_t name_len)
{
    uint32_t i;
    for (i = 0; i < layout->cond_count; i++) {
        if (strlen(layout->conditions[i].name) == name_len
                && memcmp(layout->conditions[i].name, name, name_len) == 0) {
            return &layout->conditions[i];
        }
    }
    return NULL;
}

#endif /* PHOPOL_LAYOUT_H */
