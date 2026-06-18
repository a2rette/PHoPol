#ifndef PHP_PHOPOL_H
#define PHP_PHOPOL_H

#include "phopol_layout.h"

#define PHP_PHOPOL_EXTNAME  "phopol"
#define PHP_PHOPOL_VERSION  "0.1.0"

extern zend_module_entry phopol_module_entry;
#define phpext_phopol_ptr &phopol_module_entry

/* Class entries */
extern zend_class_entry *phopol_level01_ce;
extern zend_class_entry *phopol_occurs_group_ce;

/* -----------------------------------------------------------------------
 * Internal object: one flat byte buffer per level(01) item.
 *
 * layout      — points to a phopol_layout_t.
 * data        — the raw byte buffer (NULL when using base_zval).
 * length      — buffer size in bytes (== layout->total_length).
 * owns_data   — 1: we allocated data with emalloc and must efree it.
 * owns_layout — 1: layout is request-scoped (OCCURS sub-view), free with efree.
 * data_offset — byte offset into base's buffer (OCCURS cells; 0 for REDEFINES).
 * base_zval   — IS_OBJECT: REDEFINES overlay or OCCURS cell sub-view.
 * std         — Zend object header, MUST be last (variable-size tail).
 * --------------------------------------------------------------------- */
typedef struct _phopol_level01_object {
    phopol_layout_t *layout;
    uint8_t         *data;
    size_t           length;
    uint8_t          owns_data;
    uint8_t          owns_layout;
    size_t           data_offset;
    zval             base_zval;
    zend_object      std;        /* MUST be last */
} phopol_level01_object;

static inline phopol_level01_object *phopol_level01_from_obj(zend_object *obj)
{
    return (phopol_level01_object *)((char *)(obj)
        - XtOffsetOf(phopol_level01_object, std));
}

#define Z_PHOPOL_LEVEL01_P(zv) \
    phopol_level01_from_obj(Z_OBJ_P(zv))

/* -----------------------------------------------------------------------
 * PHoPolOccursGroup — proxy returned by $level01->groupName
 * Implements [idx] access via read_dimension handler.
 * --------------------------------------------------------------------- */
typedef struct _phopol_occurs_group_object {
    /* NOTE: parent_zval and cached_cell MUST remain the first two fields and
     * stay consecutive.  phopol_occurs_group_get_gc() exposes them to the Zend
     * cycle-collector as a two-element zval array via a single pointer. */
    zval             parent_zval;    /* the owning PHoPolLevel01 instance       */
    zval             cached_cell;    /* reusable cell sub-view (IS_UNDEF = none)*/
    char            *group_name;     /* estrndup'd group name, e.g. "month"     */
    size_t           group_name_len;
    uint16_t         occurs_max;     /* physical max cells (from OCCURS clause) */
    char            *depending_on;   /* NULL or ODO field name in parent layout */
    size_t           depending_on_len;
    phopol_layout_t *sub_layout;     /* precomputed sub-layout (request-scoped) */
    size_t           group_base;     /* byte offset of group start in record    */
    size_t           entry_size;     /* bytes per cell                          */
    zend_object      std;            /* MUST be last                            */
} phopol_occurs_group_object;

static inline phopol_occurs_group_object *phopol_occurs_group_from_obj(zend_object *obj)
{
    return (phopol_occurs_group_object *)((char *)(obj)
        - XtOffsetOf(phopol_occurs_group_object, std));
}

#define Z_PHOPOL_OCCURS_GROUP_P(zv) \
    phopol_occurs_group_from_obj(Z_OBJ_P(zv))

/* Layout registry — defined in phopol.c, used by phopol_level.c */
extern HashTable *phopol_layouts;

/* Called from PHP_MINIT_FUNCTION to register both object classes */
void phopol_level_minit(void);

/* Called from PHP_MSHUTDOWN_FUNCTION to free the persistent sub-layout cache */
void phopol_level_mshutdown(void);

/* Returns the live data pointer for any level01 object (follows REDEFINES/OCCURS chains).
 * Iterative so the compiler can inline it at every call site. */
static inline uint8_t *phopol_get_data(phopol_level01_object *intern)
{
    size_t offset = 0;
    while (Z_TYPE(intern->base_zval) == IS_OBJECT) {
        offset += intern->data_offset;
        intern = phopol_level01_from_obj(Z_OBJ(intern->base_zval));
    }
    return intern->data ? intern->data + offset : NULL;
}

#endif /* PHP_PHOPOL_H */
