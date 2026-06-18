#ifndef PHOPOL_LAYOUT_H
#define PHOPOL_LAYOUT_H

#include <stdint.h>
#include <stddef.h>
#include <string.h>

/* -----------------------------------------------------------------------
 * Storage types — mirrors FieldType enum in PHoPolField.php
 * --------------------------------------------------------------------- */
typedef enum _phopol_type {
    PHOPOL_TYPE_DISPLAY = 0,   /* PIC X / PIC 9  — alpha or zoned decimal */
    PHOPOL_TYPE_BINARY  = 1,   /* USAGE BINARY / COMP — two's complement  */
    PHOPOL_TYPE_PACKED  = 2,   /* USAGE COMP-3  — packed decimal (BCD)    */
    PHOPOL_TYPE_FLOAT32 = 3,   /* USAGE COMP-1  — IEEE 754 single (4 B)   */
    PHOPOL_TYPE_FLOAT64 = 4,   /* USAGE COMP-2  — IEEE 754 double (8 B)   */
    PHOPOL_TYPE_NATIVE  = 5,   /* USAGE COMP-5  — machine-native integer  */
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
    const char    *name;       /* field name (interned — never freed)      */
    size_t         offset;     /* byte offset from start of level(01) buf  */
    size_t         length;     /* total byte length of this field          */
    phopol_type_t  type;       /* storage type                             */
    uint8_t        digits;     /* total digit count  (0 for alpha/string)  */
    uint8_t        decimals;   /* decimal places  (m in V9(m))             */
    uint8_t        flags;      /* PHOPOL_FLAG_* bitmask                    */
} phopol_field_t;

/* -----------------------------------------------------------------------
 * phopol_layout_t — compile-time descriptor for one level(01) level
 * --------------------------------------------------------------------- */
typedef struct _phopol_layout {
    const char      *name;          /* level name, e.g. "WsIdentification" */
    size_t           total_length;  /* total buffer size in bytes            */
    uint32_t         field_count;   /* number of entries in fields[]         */
    phopol_field_t  *fields;        /* flat array of field descriptors       */
} phopol_layout_t;

/* -----------------------------------------------------------------------
 * phopol_find_field — linear scan by name.
 * O(n) now; the cache_slot mechanism will cache the result per call site.
 * --------------------------------------------------------------------- */
static inline phopol_field_t *phopol_find_field(
    phopol_layout_t *layout, const char *name, size_t name_len)
{
    uint32_t i;
    for (i = 0; i < layout->field_count; i++) {
        if (strlen(layout->fields[i].name) == name_len
                && memcmp(layout->fields[i].name, name, name_len) == 0) {
            return &layout->fields[i];
        }
    }
    return NULL;
}

#endif /* PHOPOL_LAYOUT_H */
