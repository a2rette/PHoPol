#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_phopol.h"
#include "phopol_codec.h"
#include "phopol_runtime.h"

extern HashTable *phopol_layouts;  /* defined in phopol.c */

/* =======================================================================
 * PERSISTENT SUB-LAYOUT CACHE
 *
 * Each unique (parent_layout, group_name) pair produces one persistent
 * phopol_occurs_info_t that lives for the PHP process lifetime.  This
 * makes the sub-layout pointer stable across requests when OPcache is
 * active, so it is safe to store in Zend cache_slots.
 *
 * Thread-safety: single-writer (lazy first-use).  Safe for PHP-FPM
 * prefork (one process per worker) and PHP CLI (single-threaded).
 * ===================================================================== */

typedef struct _phopol_occurs_info {
    phopol_layout_t *sub_layout;    /* persistent; freed in phopol_level_mshutdown */
    size_t           group_base;    /* byte offset of group start in parent record  */
    size_t           entry_size;    /* bytes per cell                               */
    uint16_t         occurs_max;    /* physical max cells                           */
    const char      *depending_on;  /* borrowed from persistent parent layout field */
} phopol_occurs_info_t;

static HashTable *phopol_sublayout_cache = NULL;

static void phopol_persistent_occurs_info_dtor(zval *zv)
{
    phopol_occurs_info_t *info = (phopol_occurs_info_t *)Z_PTR_P(zv);
    phopol_layout_t *sub = info->sub_layout;
    uint32_t i;
    zend_hash_destroy(sub->fields_ht);
    pefree(sub->fields_ht, 1);
    for (i = 0; i < sub->field_count; i++) {
        pefree((void *)sub->fields[i].name, 1);
        /* edit_mask is borrowed from the persistent parent layout — do not free */
    }
    pefree(sub->fields, 1);
    pefree(sub, 1);
    pefree(info, 1);
}

/* Returns the persistent occurs info for (parent_layout, group_name),
 * building and caching it on first use.
 * Returns NULL if group_name is not an OCCURS group in parent_layout. */
static phopol_occurs_info_t *phopol_get_occurs_info(
    phopol_layout_t *parent_layout,
    const char      *group_name,
    size_t           group_name_len)
{
    char   cache_key[320];
    size_t key_len = (size_t)snprintf(cache_key, sizeof(cache_key) - 1,
        "%p:%.*s", (void *)parent_layout, (int)group_name_len, group_name);

    zval *cached_zv = zend_hash_str_find(phopol_sublayout_cache, cache_key, key_len);
    if (cached_zv) {
        return (phopol_occurs_info_t *)Z_PTR_P(cached_zv);
    }

    char   prefix[128];
    size_t pfx_len = (size_t)snprintf(prefix, sizeof(prefix) - 1,
                                      "%.*s[*]_", (int)group_name_len, group_name);

    size_t   group_base  = 0;
    size_t   entry_size  = 0;
    uint16_t occurs_max  = 0;
    uint32_t sub_count   = 0;
    uint32_t i;
    int      found_outer = 0;
    const char *depending_on = NULL;

    for (i = 0; i < parent_layout->field_count; i++) {
        phopol_field_t *f = &parent_layout->fields[i];
        if (strncmp(f->name, prefix, pfx_len) != 0) continue;
        if (sub_count == 0) group_base = f->offset;
        if (!found_outer && strstr(f->name + pfx_len, "[*]_") == NULL) {
            entry_size   = f->entry_size;
            occurs_max   = f->occurs_max;
            depending_on = f->depending_on;  /* persistent pointer from parent */
            found_outer  = 1;
        }
        sub_count++;
    }
    if (sub_count == 0) return NULL;

    phopol_layout_t *sub = pemalloc(sizeof(phopol_layout_t), 1);
    sub->name                   = NULL;
    sub->total_length           = entry_size;
    sub->field_count            = sub_count;
    sub->fields                 = pemalloc(sub_count * sizeof(phopol_field_t), 1);
    sub->cond_count             = 0;
    sub->conditions             = NULL;
    sub->decimal_point_is_comma = parent_layout->decimal_point_is_comma;

    uint32_t si = 0;
    for (i = 0; i < parent_layout->field_count; i++) {
        phopol_field_t *f = &parent_layout->fields[i];
        if (strncmp(f->name, prefix, pfx_len) != 0) continue;
        phopol_field_t *sf = &sub->fields[si++];
        const char *short_name = f->name + pfx_len;
        sf->name              = pestrndup(short_name, strlen(short_name), 1);
        sf->offset            = f->offset - group_base;
        sf->length            = f->length;
        sf->type              = f->type;
        sf->digits            = f->digits;
        sf->decimals          = f->decimals;
        sf->flags             = f->flags;
        sf->edit_mask         = f->edit_mask;   /* borrowed persistent pointer */
        sf->depending_on      = NULL;
        sf->initial_value     = NULL;
        sf->initial_value_len = 0;
        sf->initial_is_fill   = 0;
        if (strstr(short_name, "[*]_") != NULL) {
            sf->occurs_max = f->occurs_max;
            sf->entry_size = f->entry_size;
        } else {
            sf->occurs_max = 1;
            sf->entry_size = 0;
        }
    }

    /* Build the O(1) field-name lookup table for the sub-layout */
    sub->fields_ht = pemalloc(sizeof(HashTable), 1);
    zend_hash_init(sub->fields_ht, sub->field_count, NULL, NULL, 1);
    {
        uint32_t fi;
        for (fi = 0; fi < sub->field_count; fi++) {
            zend_hash_str_add_ptr(sub->fields_ht,
                sub->fields[fi].name, strlen(sub->fields[fi].name),
                &sub->fields[fi]);
        }
    }

    phopol_occurs_info_t *info = pemalloc(sizeof(phopol_occurs_info_t), 1);
    info->sub_layout   = sub;
    info->group_base   = group_base;
    info->entry_size   = entry_size;
    info->occurs_max   = occurs_max;
    info->depending_on = depending_on;

    zend_hash_str_add_ptr(phopol_sublayout_cache, cache_key, key_len, info);
    return info;
}

/* =======================================================================
 * CLASS: OBJECT LIFECYCLE
 * ===================================================================== */

zend_class_entry            *phopol_level01_ce;
static zend_object_handlers  phopol_level01_handlers;
zend_class_entry            *phopol_occurs_group_ce;
static zend_object_handlers  phopol_occurs_group_handlers;

static zend_object *phopol_level01_create_object(zend_class_entry *ce)
{
    phopol_level01_object *intern =
        zend_object_alloc(sizeof(phopol_level01_object), ce);

    intern->layout      = NULL;
    intern->data        = NULL;
    intern->length      = 0;
    intern->owns_data   = 0;
    intern->owns_layout = 0;
    intern->data_offset = 0;
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
        /* Release any zend_string* pointers stored by PHP_STRING fields */
        phopol_layout_t *layout = intern->layout;
        if (layout) {
            uint32_t fi;
            for (fi = 0; fi < layout->field_count; fi++) {
                phopol_field_t *f = &layout->fields[fi];
                if (f->type != PHOPOL_TYPE_PHP_STRING) continue;
                uint32_t cnt  = (f->occurs_max > 1) ? f->occurs_max : 1;
                size_t   step = (f->occurs_max > 1) ? f->entry_size : 0;
                uint32_t ci;
                for (ci = 0; ci < cnt; ci++) {
                    zend_string *s;
                    memcpy(&s, intern->data + f->offset + ci * step, sizeof(zend_string *));
                    if (s) zend_string_release(s);
                }
            }
        }
        efree(intern->data);
        intern->data = NULL;
    }
    /* Sub-layouts are always persistent (owned by phopol_sublayout_cache).
     * owns_layout is always 0; no sub-layout is freed here. */
    if (Z_TYPE(intern->base_zval) == IS_OBJECT) {
        zval_ptr_dtor(&intern->base_zval);
    }

    zend_object_std_dtor(obj);
}

/* =======================================================================
 * DEPENDING ON helper — returns the effective logical upper bound for an
 * OCCURS group.
 * ===================================================================== */
static zend_long phopol_get_logical_max(phopol_occurs_group_object *go)
{
    zend_long logical = (zend_long)go->occurs_max;
    if (go->depending_on && go->depending_on_len > 0) {
        phopol_level01_object *par =
            phopol_level01_from_obj(Z_OBJ(go->parent_zval));
        uint8_t *par_data = phopol_get_data(par);
        if (par_data && par->layout) {
            phopol_field_t *dep = phopol_find_field(
                par->layout, go->depending_on, go->depending_on_len);
            if (dep) {
                zval tmp;
                phopol_decode_field(par_data, dep, &tmp);
                zend_long val = zval_get_long(&tmp);
                zval_ptr_dtor(&tmp);
                if (val < 0) val = 0;
                if (val > logical) val = logical;
                logical = val;
            }
        }
    }
    return logical;
}

/* =======================================================================
 * phopol_cell_build — used by the cell() PHP method.
 * Builds a PHoPolLevel01 sub-view for a given group + index.
 * Uses the persistent sub-layout from phopol_get_occurs_info.
 * ===================================================================== */
static int phopol_cell_build(
    phopol_level01_object *parent_intern,
    zend_object           *parent_obj,
    const char            *group_name,
    size_t                 group_name_len,
    zend_long              idx,
    zval                  *return_value)
{
    phopol_occurs_info_t *info = phopol_get_occurs_info(
        parent_intern->layout, group_name, group_name_len);

    if (!info) {
        zend_throw_error(NULL,
            "PHoPolLevel01: no OCCURS sub-fields for group \"%s\"", group_name);
        return -1;
    }

    zend_long logical_max = (zend_long)info->occurs_max;
    if (info->depending_on) {
        uint8_t *par_data = phopol_get_data(parent_intern);
        if (par_data && parent_intern->layout) {
            phopol_field_t *dep = phopol_find_field(
                parent_intern->layout, info->depending_on, strlen(info->depending_on));
            if (dep) {
                zval tmp;
                phopol_decode_field(par_data, dep, &tmp);
                zend_long val = zval_get_long(&tmp);
                zval_ptr_dtor(&tmp);
                if (val < 0) val = 0;
                if (val > logical_max) val = logical_max;
                logical_max = val;
            }
        }
    }
    if (idx < 1 || (info->occurs_max > 0 && idx > logical_max)) {
        zend_throw_error(NULL,
            "PHoPolLevel01: OCCURS index %ld out of range 1..%ld",
            (long)idx, (long)logical_max);
        return -1;
    }

    object_init_ex(return_value, phopol_level01_ce);
    phopol_level01_object *sv = phopol_level01_from_obj(Z_OBJ_P(return_value));
    sv->layout      = info->sub_layout;  /* persistent — cell does NOT own it */
    sv->owns_layout = 0;
    sv->data        = NULL;
    sv->owns_data   = 0;
    sv->data_offset = info->group_base + (size_t)(idx - 1) * info->entry_size;
    ZVAL_OBJ_COPY(&sv->base_zval, parent_obj);

    return 0;
}

/* =======================================================================
 * CLASS: PHoPolOccursGroup
 *
 * Proxy returned by $level01->groupName when the name matches an OCCURS
 * group.  Implements array-style access: $level01->groupName[idx].
 * ===================================================================== */

static zend_object *phopol_occurs_group_create_object(zend_class_entry *ce)
{
    phopol_occurs_group_object *intern =
        zend_object_alloc(sizeof(phopol_occurs_group_object), ce);
    ZVAL_UNDEF(&intern->parent_zval);
    ZVAL_UNDEF(&intern->cached_cell);
    intern->group_name      = NULL;
    intern->group_name_len  = 0;
    intern->occurs_max      = 0;
    intern->depending_on    = NULL;
    intern->depending_on_len = 0;
    intern->sub_layout      = NULL;
    intern->group_base      = 0;
    intern->entry_size      = 0;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &phopol_occurs_group_handlers;
    return &intern->std;
}

static void phopol_occurs_group_free_object(zend_object *obj)
{
    phopol_occurs_group_object *intern = phopol_occurs_group_from_obj(obj);
    if (intern->group_name)   { efree(intern->group_name);   intern->group_name   = NULL; }
    if (intern->depending_on) { efree(intern->depending_on); intern->depending_on = NULL; }
    /* sub_layout is persistent (owned by phopol_sublayout_cache) — do NOT free */
    if (Z_TYPE(intern->cached_cell) == IS_OBJECT) zval_ptr_dtor(&intern->cached_cell);
    if (Z_TYPE(intern->parent_zval) == IS_OBJECT) zval_ptr_dtor(&intern->parent_zval);
    zend_object_std_dtor(obj);
}

static HashTable *phopol_occurs_group_get_gc(
    zend_object *obj, zval **table, int *n)
{
    phopol_occurs_group_object *intern = phopol_occurs_group_from_obj(obj);
    /* parent_zval and cached_cell are consecutive (guaranteed by struct layout)
     * so a single pointer + count exposes both to the cycle collector. */
    *table = &intern->parent_zval;
    *n     = (Z_TYPE(intern->cached_cell) != IS_UNDEF) ? 2 : 1;
    return zend_std_get_properties(obj);
}

static zval *phopol_occurs_group_read_dimension(
    zend_object *obj, zval *offset, int type, zval *rv)
{
    phopol_occurs_group_object *intern = phopol_occurs_group_from_obj(obj);
    if (!offset) {
        zend_throw_error(NULL, "PHoPolOccursGroup: index is required (use [1..N])");
        ZVAL_NULL(rv); return rv;
    }
    zend_long idx = zval_get_long(offset);

    zend_long logical_max = phopol_get_logical_max(intern);
    if (idx < 1 || (intern->occurs_max > 0 && idx > logical_max)) {
        zend_throw_error(NULL,
            "PHoPolLevel01: OCCURS index %ld out of range 1..%ld",
            (long)idx, (long)logical_max);
        ZVAL_NULL(rv); return rv;
    }

    size_t new_offset = intern->group_base + (size_t)(idx - 1) * intern->entry_size;

    /* Fast path: reuse the cached cell when the proxy is the sole owner.
     * GC_REFCOUNT == 1 means no PHP variable currently holds the cell, so
     * updating data_offset in place is safe — no aliasing can observe it. */
    if (Z_TYPE(intern->cached_cell) == IS_OBJECT &&
            GC_REFCOUNT(Z_OBJ(intern->cached_cell)) == 1) {
        phopol_level01_object *sv = phopol_level01_from_obj(Z_OBJ(intern->cached_cell));
        sv->data_offset = new_offset;
        ZVAL_COPY(rv, &intern->cached_cell);    /* refcount 1 → 2 */
        return rv;
    }

    /* Slow path: allocate a fresh cell sub-view. */
    object_init_ex(rv, phopol_level01_ce);
    {
        phopol_level01_object *sv = phopol_level01_from_obj(Z_OBJ_P(rv));
        sv->layout      = intern->sub_layout;   /* persistent — cell does NOT own it */
        sv->owns_layout = 0;
        sv->data        = NULL;
        sv->owns_data   = 0;
        sv->data_offset = new_offset;
        ZVAL_OBJ_COPY(&sv->base_zval, Z_OBJ(intern->parent_zval));
    }

    /* Replace the cached cell safely. */
    {
        zval old;
        ZVAL_COPY_VALUE(&old, &intern->cached_cell);
        ZVAL_COPY(&intern->cached_cell, rv);    /* refcount 1 → 2 */
        zval_ptr_dtor(&old);
    }

    return rv;
}

static void phopol_occurs_group_write_dimension(
    zend_object *obj, zval *offset, zval *value)
{
    if (value && Z_TYPE_P(value) == IS_OBJECT
              && Z_OBJCE_P(value) == phopol_level01_ce) {
        return;
    }
    zend_throw_error(NULL,
        "PHoPolOccursGroup: cannot assign to a cell directly — "
        "access fields on the sub-view returned by [idx]");
}

static int phopol_occurs_group_has_dimension(
    zend_object *obj, zval *offset, int check_empty)
{
    phopol_occurs_group_object *intern = phopol_occurs_group_from_obj(obj);
    zend_long idx = offset ? zval_get_long(offset) : 0;
    zend_long logical_max = phopol_get_logical_max(intern);
    return (idx >= 1 &&
            (intern->occurs_max == 0 || idx <= logical_max));
}

static void phopol_occurs_group_unset_dimension(zend_object *obj, zval *offset)
{
    zend_throw_error(NULL, "PHoPolOccursGroup: cannot unset an OCCURS cell");
}

/* =======================================================================
 * CLASS: PROPERTY HANDLERS
 * ===================================================================== */

/* Build and cache a PHoPolOccursGroup proxy for a given group name.
 * Uses the persistent sub-layout from phopol_get_occurs_info.
 * Stores the proxy in the parent's standard property table and returns a
 * pointer to that slot (persistent for the object's lifetime).
 * Returns NULL if the name is not an OCCURS group. */
static zval *phopol_make_occurs_proxy(
    zend_object *obj, phopol_level01_object *intern, zend_string *name)
{
    phopol_occurs_info_t *info = phopol_get_occurs_info(
        intern->layout, ZSTR_VAL(name), ZSTR_LEN(name));
    if (!info) return NULL;

    zval proxy;
    object_init_ex(&proxy, phopol_occurs_group_ce);
    phopol_occurs_group_object *go = phopol_occurs_group_from_obj(Z_OBJ(proxy));
    ZVAL_OBJ_COPY(&go->parent_zval, obj);
    go->group_name      = estrndup(ZSTR_VAL(name), ZSTR_LEN(name));
    go->group_name_len  = ZSTR_LEN(name);
    go->occurs_max      = info->occurs_max;
    go->sub_layout      = info->sub_layout;  /* persistent — proxy does NOT own it */
    go->group_base      = info->group_base;
    go->entry_size      = info->entry_size;
    if (info->depending_on && info->depending_on[0]) {
        go->depending_on     = estrndup(info->depending_on, strlen(info->depending_on));
        go->depending_on_len = strlen(info->depending_on);
    }

    HashTable *props = zend_std_get_properties(obj);
    return zend_hash_add(props, name, &proxy);
}

/* PHP uses get_property_ptr_ptr (not read_property) when a property access is
 * part of a write chain: $obj->prop[idx]->field = val  →  FETCH_OBJ_W ->prop.
 * For OCCURS group names we cache a PHoPolOccursGroup proxy in the object's
 * standard property table and return a pointer to that slot. */
static zval *phopol_level01_get_property_ptr_ptr(
    zend_object *obj, zend_string *name, int type, void **cache_slot)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);
    if (!intern->layout) return NULL;

    HashTable *props = zend_std_get_properties(obj);
    zval *cached = zend_hash_find(props, name);
    if (cached) return cached;

    return phopol_make_occurs_proxy(obj, intern, name);
}

/* =======================================================================
 * cache_slot convention for read_property / write_property:
 *
 *   cache_slot[0] = (void *)phopol_layout_t *  — guard (NULL = unpopulated)
 *   cache_slot[1] = tagged pointer:
 *       low bit 0 → phopol_field_t *     (regular field)
 *       low bit 1 → phopol_condition_t * (88-level condition name)
 *
 * phopol_field_t and phopol_condition_t are both at minimum 4-byte aligned,
 * so the low bit of a valid pointer is always 0.
 *
 * Using the layout pointer as guard (not ce) means that two PHoPolLevel01
 * objects with DIFFERENT layouts at the same opcode site correctly miss the
 * cache.  Because all sub-layouts are now persistent (pemalloc, owned by
 * phopol_sublayout_cache), layout pointers are stable across requests even
 * when OPcache is active.
 * ===================================================================== */

static zval *phopol_level01_read_property(
    zend_object *obj, zend_string *name, int type, void **cache_slot, zval *rv)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);
    uint8_t *data = phopol_get_data(intern);

    /* Fast path: layout + field/condition pointer cached at this opcode site. */
    if (EXPECTED(cache_slot != NULL && cache_slot[0] != NULL
              && cache_slot[0] == (void *)intern->layout && data != NULL)) {
        void *p = cache_slot[1];
        if (!((uintptr_t)p & 1)) {
            return phopol_decode_field(data, (phopol_field_t *)p, rv);
        } else {
            phopol_condition_t *cond =
                (phopol_condition_t *)((uintptr_t)p & ~(uintptr_t)1);
            ZVAL_BOOL(rv, phopol_eval_condition(data, intern->layout, cond));
            return rv;
        }
    }

    /* Slow path — first access at this opcode site, or layout mismatch. */
    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout — did you call __construct()?");
        ZVAL_NULL(rv); return rv;
    }
    if (!data) {
        zend_throw_error(NULL, "PHoPolLevel01: no buffer — call allocate() or attach() first");
        ZVAL_NULL(rv); return rv;
    }

    phopol_field_t *field =
        phopol_find_field(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name));
    if (field) {
        if (cache_slot) {
            cache_slot[0] = (void *)intern->layout;
            cache_slot[1] = (void *)field;  /* low bit 0 = field */
        }
        return phopol_decode_field(data, field, rv);
    }

    phopol_condition_t *cond =
        phopol_find_condition(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name));
    if (cond) {
        if (cache_slot) {
            cache_slot[0] = (void *)intern->layout;
            cache_slot[1] = (void *)((uintptr_t)cond | (uintptr_t)1);  /* low bit 1 = cond */
        }
        ZVAL_BOOL(rv, phopol_eval_condition(data, intern->layout, cond));
        return rv;
    }

    /* Check for OCCURS group name — return a PHoPolOccursGroup proxy.
     * Proxy is cached in the props table, not in cache_slot (it's an object). */
    {
        HashTable *props = zend_std_get_properties(obj);
        zval *cached_proxy = zend_hash_find(props, name);
        if (cached_proxy && Z_TYPE_P(cached_proxy) == IS_OBJECT
                         && Z_OBJCE_P(cached_proxy) == phopol_occurs_group_ce) {
            ZVAL_COPY(rv, cached_proxy);
            return rv;
        }
        zval *slot = phopol_make_occurs_proxy(obj, intern, name);
        if (slot) {
            ZVAL_COPY(rv, slot);
            return rv;
        }
    }

    zend_throw_error(NULL, "PHoPolLevel01: unknown field \"%s\"", ZSTR_VAL(name));
    ZVAL_NULL(rv); return rv;
}

static zval *phopol_level01_write_property(
    zend_object *obj, zend_string *name, zval *value, void **cache_slot)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);
    uint8_t *data = phopol_get_data(intern);

    /* Fast path: layout + field/condition pointer cached at this opcode site. */
    if (EXPECTED(cache_slot != NULL && cache_slot[0] != NULL
              && cache_slot[0] == (void *)intern->layout && data != NULL)) {
        void *p = cache_slot[1];
        if (!((uintptr_t)p & 1)) {
            phopol_encode_field(data, (phopol_field_t *)p, value,
                                intern->layout->decimal_point_is_comma);
            return value;
        } else {
            /* Condition write (SET TO TRUE/FALSE) — still need source field lookup. */
            phopol_condition_t *cond =
                (phopol_condition_t *)((uintptr_t)p & ~(uintptr_t)1);
            if (zend_is_true(value)) {
                phopol_field_t *src = phopol_find_field(
                    intern->layout, cond->src_field, strlen(cond->src_field));
                if (src) {
                    zval zv;
                    if (cond->kind == PHOPOL_COND_VALUES && cond->value_count > 0) {
                        ZVAL_STRING(&zv, cond->values[0]);
                    } else {
                        ZVAL_LONG(&zv, (zend_long)cond->range_lo);
                    }
                    phopol_encode_field(data, src, &zv,
                                        intern->layout->decimal_point_is_comma);
                    zval_ptr_dtor(&zv);
                }
            }
            return value;
        }
    }

    /* Slow path */
    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout — did you call __construct()?");
        return value;
    }
    if (!data) {
        zend_throw_error(NULL, "PHoPolLevel01: no buffer — call allocate() or attach() first");
        return value;
    }

    phopol_field_t *field =
        phopol_find_field(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name));
    if (field) {
        if (cache_slot) {
            cache_slot[0] = (void *)intern->layout;
            cache_slot[1] = (void *)field;
        }
        phopol_encode_field(data, field, value, intern->layout->decimal_point_is_comma);
        return value;
    }

    phopol_condition_t *cond =
        phopol_find_condition(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name));
    if (cond) {
        if (cache_slot) {
            cache_slot[0] = (void *)intern->layout;
            cache_slot[1] = (void *)((uintptr_t)cond | (uintptr_t)1);
        }
        if (zend_is_true(value)) {
            phopol_field_t *src = phopol_find_field(
                intern->layout, cond->src_field, strlen(cond->src_field));
            if (src) {
                zval zv;
                if (cond->kind == PHOPOL_COND_VALUES && cond->value_count > 0) {
                    ZVAL_STRING(&zv, cond->values[0]);
                } else {
                    ZVAL_LONG(&zv, (zend_long)cond->range_lo);
                }
                phopol_encode_field(data, src, &zv, intern->layout->decimal_point_is_comma);
                zval_ptr_dtor(&zv);
            }
        }
        return value;
    }

    zend_throw_error(NULL, "PHoPolLevel01: unknown field \"%s\"", ZSTR_VAL(name));
    return value;
}

static int phopol_level01_has_property(
    zend_object *obj, zend_string *name, int has_set_exists, void **cache_slot)
{
    phopol_level01_object *intern = phopol_level01_from_obj(obj);
    if (!intern->layout) return 0;
    if (phopol_find_field(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name))) return 1;
    if (phopol_find_condition(intern->layout, ZSTR_VAL(name), ZSTR_LEN(name))) return 1;
    {
        HashTable *props = zend_std_get_properties(obj);
        zval *cached = zend_hash_find(props, name);
        if (cached && Z_TYPE_P(cached) == IS_OBJECT
                   && Z_OBJCE_P(cached) == phopol_occurs_group_ce) return 1;
        char   prefix[128];
        size_t pfx_len = (size_t)snprintf(prefix, sizeof(prefix) - 1,
                                          "%s[*]_", ZSTR_VAL(name));
        uint32_t i;
        for (i = 0; i < intern->layout->field_count; i++) {
            if (strncmp(intern->layout->fields[i].name, prefix, pfx_len) == 0) return 1;
        }
    }
    return 0;
}

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

PHP_METHOD(PHoPolLevel01, allocate)
{
    ZEND_PARSE_PARAMETERS_NONE();

    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);

    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout — call __construct() first");
        RETURN_THROWS();
    }
    if (intern->owns_layout) {
        zend_throw_error(NULL, "PHoPolLevel01: cannot allocate() on an OCCURS cell sub-view");
        RETURN_THROWS();
    }
    if (intern->owns_data && intern->data) {
        /* Release PHP_STRING pointers before freeing the old buffer */
        uint32_t fi;
        for (fi = 0; fi < intern->layout->field_count; fi++) {
            phopol_field_t *f = &intern->layout->fields[fi];
            if (f->type != PHOPOL_TYPE_PHP_STRING) continue;
            uint32_t cnt  = (f->occurs_max > 1) ? f->occurs_max : 1;
            size_t   step = (f->occurs_max > 1) ? f->entry_size : 0;
            uint32_t ci;
            for (ci = 0; ci < cnt; ci++) {
                zend_string *s;
                memcpy(&s, intern->data + f->offset + ci * step, sizeof(zend_string *));
                if (s) zend_string_release(s);
            }
        }
        efree(intern->data);
    }

    phopol_layout_t *layout = intern->layout;
    size_t len = layout->total_length;
    intern->data      = emalloc(len);
    intern->length    = len;
    intern->owns_data = 1;
    memset(intern->data, ' ', len);

    /* Single pass: apply VALUE clauses where present; otherwise apply COBOL
     * defaults — ZERO for numeric/binary/float/php fields, NULL for php string.
     * DISPLAY alpha fields with no VALUE are already correct (space-filled). */
    {
        zval zero;
        ZVAL_LONG(&zero, 0);
        uint32_t fi;
        for (fi = 0; fi < layout->field_count; fi++) {
            phopol_field_t *f = &layout->fields[fi];
            uint32_t cnt  = (f->occurs_max > 1) ? f->occurs_max : 1;
            size_t   step = (f->occurs_max > 1) ? f->entry_size : 0;
            uint32_t ci;

            if (f->initial_value) {
                if (f->initial_is_fill) {
                    uint8_t fill_byte = (uint8_t)f->initial_value[0];
                    for (ci = 0; ci < cnt; ci++)
                        memset(intern->data + f->offset + ci * step, fill_byte, f->length);
                } else {
                    zval zv;
                    ZVAL_STRINGL(&zv, f->initial_value, f->initial_value_len);
                    for (ci = 0; ci < cnt; ci++)
                        phopol_encode_field(intern->data + ci * step, f, &zv,
                                            layout->decimal_point_is_comma);
                    zval_ptr_dtor(&zv);
                }
            } else if (f->type == PHOPOL_TYPE_PHP_STRING) {
                for (ci = 0; ci < cnt; ci++)
                    memset(intern->data + f->offset + ci * step, 0, f->length);
            } else if (f->type != PHOPOL_TYPE_DISPLAY || f->digits > 0) {
                for (ci = 0; ci < cnt; ci++)
                    phopol_encode_field(intern->data + ci * step, f, &zero,
                                        layout->decimal_point_is_comma);
            }
            /* else: DISPLAY alpha, no VALUE → space-fill already correct */
        }
    }
}

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
    if (intern->owns_layout) {
        zend_throw_error(NULL, "PHoPolLevel01: cannot attach() on an OCCURS cell sub-view");
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

    if (Z_TYPE(intern->base_zval) == IS_OBJECT) {
        zval_ptr_dtor(&intern->base_zval);
    }
    if (intern->owns_data && intern->data) {
        efree(intern->data);
        intern->data      = NULL;
        intern->owns_data = 0;
    }

    ZVAL_OBJ_COPY(&intern->base_zval, Z_OBJ_P(base_zv));
}

PHP_METHOD(PHoPolLevel01, cell)
{
    zend_string *group_name;
    zend_long    idx;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(group_name)
        Z_PARAM_LONG(idx)
    ZEND_PARSE_PARAMETERS_END();

    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);
    if (!intern->layout) {
        zend_throw_error(NULL, "PHoPolLevel01: no layout");
        RETURN_THROWS();
    }
    if (!phopol_get_data(intern)) {
        zend_throw_error(NULL, "PHoPolLevel01: no buffer — call allocate() or attach() first");
        RETURN_THROWS();
    }

    if (phopol_cell_build(intern, Z_OBJ_P(ZEND_THIS),
                          ZSTR_VAL(group_name), ZSTR_LEN(group_name),
                          idx, return_value) < 0) {
        RETURN_THROWS();
    }
}

PHP_METHOD(PHoPolLevel01, __toString)
{
    ZEND_PARSE_PARAMETERS_NONE();
    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);
    uint8_t *data = phopol_get_data(intern);
    if (!data || !intern->layout) { RETURN_EMPTY_STRING(); }
    RETURN_STRINGL((char *)data, intern->layout->total_length);
}

PHP_METHOD(PHoPolLevel01, rawBytes)
{
    ZEND_PARSE_PARAMETERS_NONE();
    phopol_level01_object *intern = Z_PHOPOL_LEVEL01_P(ZEND_THIS);
    uint8_t *data = phopol_get_data(intern);
    if (!data || !intern->layout) { RETURN_EMPTY_STRING(); }
    RETURN_STRINGL((char *)data, intern->layout->total_length);
}

/* =======================================================================
 * ARGINFO AND CORE METHOD TABLE
 * ===================================================================== */

ZEND_BEGIN_ARG_INFO_EX(arginfo___construct, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, layoutName, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_allocate, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_attach, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, bytes, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_attachTo, 0, 1, IS_VOID, 0)
    ZEND_ARG_OBJ_INFO(0, base, PHoPolLevel01, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_cell, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, group, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, idx,   IS_LONG,   0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_rawBytes, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo___toString arginfo_rawBytes

static const zend_function_entry phopol_level01_core_methods[] = {
    PHP_ME(PHoPolLevel01, __construct, arginfo___construct, ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, __toString,  arginfo___toString,  ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, allocate,    arginfo_allocate,    ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, attach,      arginfo_attach,      ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, attachTo,    arginfo_attachTo,    ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, cell,        arginfo_cell,        ZEND_ACC_PUBLIC)
    PHP_ME(PHoPolLevel01, rawBytes,    arginfo_rawBytes,    ZEND_ACC_PUBLIC)
    PHP_FE_END
};

/* =======================================================================
 * MODULE INIT / SHUTDOWN HOOKS
 * ===================================================================== */

void phopol_level_minit(void)
{
    /* Persistent sub-layout cache */
    phopol_sublayout_cache = pemalloc(sizeof(HashTable), 1);
    zend_hash_init(phopol_sublayout_cache, 32, NULL,
                   phopol_persistent_occurs_info_dtor, 1);

    zend_class_entry ce;
    INIT_CLASS_ENTRY(ce, "PHoPolLevel01", phopol_level01_core_methods);
    phopol_level01_ce = zend_register_internal_class(&ce);
    phopol_level01_ce->create_object = phopol_level01_create_object;

    memcpy(&phopol_level01_handlers,
           zend_get_std_object_handlers(),
           sizeof(zend_object_handlers));
    phopol_level01_handlers.offset                = XtOffsetOf(phopol_level01_object, std);
    phopol_level01_handlers.free_obj              = phopol_level01_free_object;
    phopol_level01_handlers.read_property         = phopol_level01_read_property;
    phopol_level01_handlers.write_property        = phopol_level01_write_property;
    phopol_level01_handlers.has_property          = phopol_level01_has_property;
    phopol_level01_handlers.get_gc                = phopol_level01_get_gc;
    phopol_level01_handlers.get_property_ptr_ptr  = phopol_level01_get_property_ptr_ptr;

    zend_class_entry ce2;
    INIT_CLASS_ENTRY(ce2, "PHoPolOccursGroup", NULL);
    phopol_occurs_group_ce = zend_register_internal_class(&ce2);
    phopol_occurs_group_ce->create_object = phopol_occurs_group_create_object;

    memcpy(&phopol_occurs_group_handlers,
           zend_get_std_object_handlers(),
           sizeof(zend_object_handlers));
    phopol_occurs_group_handlers.offset          = XtOffsetOf(phopol_occurs_group_object, std);
    phopol_occurs_group_handlers.free_obj        = phopol_occurs_group_free_object;
    phopol_occurs_group_handlers.get_gc          = phopol_occurs_group_get_gc;
    phopol_occurs_group_handlers.read_dimension  = phopol_occurs_group_read_dimension;
    phopol_occurs_group_handlers.write_dimension = phopol_occurs_group_write_dimension;
    phopol_occurs_group_handlers.has_dimension   = phopol_occurs_group_has_dimension;
    phopol_occurs_group_handlers.unset_dimension = phopol_occurs_group_unset_dimension;

    phopol_runtime_minit();
}

void phopol_level_mshutdown(void)
{
    if (phopol_sublayout_cache) {
        zend_hash_destroy(phopol_sublayout_cache);
        pefree(phopol_sublayout_cache, 1);
        phopol_sublayout_cache = NULL;
    }
}
