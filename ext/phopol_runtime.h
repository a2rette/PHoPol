#ifndef PHOPOL_RUNTIME_H
#define PHOPOL_RUNTIME_H

/* php.h must be included by the translation unit before this header */
#include "phopol_layout.h"

void phopol_initialize(uint8_t *data, phopol_layout_t *layout);

/* Called from phopol_level_minit() to bolt verb methods onto PHoPolLevel01 */
void phopol_runtime_minit(void);

#endif /* PHOPOL_RUNTIME_H */
