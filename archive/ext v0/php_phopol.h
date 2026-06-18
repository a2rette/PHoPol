#ifndef PHP_PHOPOL_H
#define PHP_PHOPOL_H

#include "phopol_layout.h"

#define PHP_PHOPOL_EXTNAME  "phopol"
#define PHP_PHOPOL_VERSION  "0.1.0"

extern zend_module_entry phopol_module_entry;
#define phpext_phopol_ptr &phopol_module_entry

/* The PHoPolLevel01 class entry */
extern zend_class_entry *phopol_level01_ce;

/* -----------------------------------------------------------------------
 * Internal object: one flat byte buffer per level(01) item.
 *
 * layout   — points to a static phopol_layout_t (never freed by us).
 * data     — the raw byte buffer.
 * length   — buffer size in bytes (== layout->total_length).
 * owns_data— 1: we allocated data with emalloc and must efree it.
 *            0: data points into external memory (zero-copy borrow).
 * std      — Zend object header, MUST be last (variable-size tail).
 * --------------------------------------------------------------------- */
typedef struct _phopol_level01_object {
    phopol_layout_t *layout;
    uint8_t         *data;
    size_t           length;
    uint8_t          owns_data;
    /* REDEFINES: if set, data pointer is read dynamically from base_zval */
    zval             base_zval;  /* IS_OBJECT → this is a redefines overlay */
    zend_object      std;        /* MUST be last */
} phopol_level01_object;

static inline phopol_level01_object *phopol_level01_from_obj(zend_object *obj)
{
    return (phopol_level01_object *)((char *)(obj)
        - XtOffsetOf(phopol_level01_object, std));
}

#define Z_PHOPOL_LEVEL01_P(zv) \
    phopol_level01_from_obj(Z_OBJ_P(zv))

#endif /* PHP_PHOPOL_H */
