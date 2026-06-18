#ifndef PHOPOL_CODEC_H
#define PHOPOL_CODEC_H

/* php.h must be included by the translation unit before this header */
#include "phopol_layout.h"

void  phopol_edit_format(uint8_t *ptr, size_t field_len, const char *mask,
                         double value, uint8_t decimal_point_is_comma);
zval *phopol_decode_field(uint8_t *data, phopol_field_t *field, zval *rv);
void  phopol_encode_field(uint8_t *data, phopol_field_t *field, zval *value,
                          uint8_t decimal_point_is_comma);
int   phopol_eval_condition(uint8_t *data, phopol_layout_t *layout,
                            phopol_condition_t *cond);

#endif /* PHOPOL_CODEC_H */
