       IDENTIFICATION DIVISION.
       PROGRAM-ID. WS-SHOWCASE.
      *================================================================
      * Comprehensive WORKING-STORAGE SECTION showcase
      * Covers: levels, PIC clauses, types, REDEFINES, RENAMES (66),
      *         condition names (88), OCCURS, USAGE clauses, etc.
      *================================================================

       DATA DIVISION.
       WORKING-STORAGE SECTION.

      *----------------------------------------------------------------
      * LEVEL 01 — Top-level group item (no PIC clause on groups)
      *----------------------------------------------------------------
       01  WS-IDENTIFICATION.
           05  WS-PROGRAM-NAME     PIC X(10) VALUE 'SHOWCASE'.
           05  WS-VERSION          PIC 9(2)  VALUE 1.
           05  WS-REVISION         PIC 9(2)  VALUE 0.


      *----------------------------------------------------------------
      * ELEMENTARY ALPHANUMERIC PICTURES (PIC X)
      *----------------------------------------------------------------
       01  WS-ALPHA-SAMPLES.
           05  WS-CHAR-1           PIC X.          *> 1 character
           05  WS-CHAR-10          PIC X(10).      *> 10 characters
           05  WS-CHAR-SPACES      PIC X(20)
                                   VALUE SPACES.   *> initialised
           05  WS-CHAR-LIT         PIC X(5)
                                   VALUE 'HELLO'. *> literal init


      *----------------------------------------------------------------
      * ELEMENTARY ALPHABETIC PICTURES (PIC A)
      * Only A-Z, a-z and spaces — rarely used in modern COBOL
      *----------------------------------------------------------------
       01  WS-ALPHA-ONLY.
           05  WS-LETTERS-ONLY     PIC A(15).      *> alpha + space only


      *----------------------------------------------------------------
      * NUMERIC PICTURES (PIC 9) — DISPLAY (zoned decimal, default)
      * Each digit stored as one byte (EBCDIC or ASCII zone + digit)
      *----------------------------------------------------------------
       01  WS-NUMERIC-DISPLAY.
           05  WS-INT-5            PIC 9(5).               *> 0..99999
           05  WS-INT-SIGNED       PIC S9(5).              *> signed
           05  WS-INT-SIGNED-SEP   PIC S9(5) SIGN LEADING
                                   SEPARATE.               *> +/- as extra byte
           05  WS-DECIMAL          PIC 9(5)V9(2).          *> 99999.99 (V = implied decimal)
           05  WS-DECIMAL-SIGNED   PIC S9(5)V9(2).
           05  WS-SMALL            PIC 9.                  *> single digit


      *----------------------------------------------------------------
      * EDITED PICTURES — for MOVE-to-display / reports
      * Not usable in arithmetic directly
      *----------------------------------------------------------------
       01  WS-EDITED-SAMPLES.
           05  WS-AMT-EDITED       PIC ZZZ,ZZ9.99.     *> suppress leading zeros
           05  WS-AMT-DOLLAR       PIC $$$,$$$,$$9.99. *> floating $
           05  WS-AMT-ASTERISK     PIC ***,**9.99.     *> cheque protection
           05  WS-AMT-SIGNED-ED    PIC +ZZZ,ZZ9.99.    *> explicit sign
           05  WS-AMT-CR-DR        PIC ZZZ,ZZ9.99CR.   *> CR/DR suffix
           05  WS-DATE-EDITED      PIC 99/99/9999.     *> slash insertion
           05  WS-PHONE-EDITED     PIC (999)B999-9999. *> B = blank insertion


      *----------------------------------------------------------------
      * USAGE BINARY (= COMP on most compilers)
      * Stored as pure two's-complement integer — efficient arithmetic
      *   PIC S9(1..4)  → 2 bytes (SMALLINT)
      *   PIC S9(5..9)  → 4 bytes (INT)
      *   PIC S9(10..18)→ 8 bytes (BIGINT)
      *----------------------------------------------------------------
       01  WS-BINARY-ITEMS.
           05  WS-BIN-SHORT        PIC S9(4)  USAGE BINARY.   *> 2 bytes
           05  WS-BIN-INT          PIC S9(9)  USAGE BINARY.   *> 4 bytes
           05  WS-BIN-LONG         PIC S9(18) USAGE BINARY.   *> 8 bytes
           05  WS-BIN-UNSIGNED     PIC 9(9)   USAGE BINARY.   *> no sign
      *    Synonym: USAGE COMP  (identical on virtually all platforms)
           05  WS-COMP-INT         PIC S9(9)  USAGE COMP.


      *----------------------------------------------------------------
      * USAGE COMP-1 / COMP-2 — floating-point (IEEE 754)
      * COMP-1 = single precision (4 bytes)
      * COMP-2 = double precision (8 bytes)
      * No PIC clause allowed (size is fixed by the type)
      *----------------------------------------------------------------
       01  WS-FLOAT-ITEMS.
           05  WS-FLOAT-SINGLE     USAGE COMP-1.   *> ~7 sig. digits
           05  WS-FLOAT-DOUBLE     USAGE COMP-2.   *> ~15 sig. digits


      *----------------------------------------------------------------
      * USAGE COMP-3 (PACKED-DECIMAL)
      * Two decimal digits per byte + one nibble for sign → compact
      * PIC S9(n)  COMP-3  occupies  CEIL((n+1)/2)  bytes
      * Most efficient for decimal arithmetic on mainframes (z/OS)
      *----------------------------------------------------------------
       01  WS-PACKED-ITEMS.
           05  WS-PACKED-5         PIC S9(5)   USAGE COMP-3. *> 3 bytes
           05  WS-PACKED-9         PIC S9(9)   USAGE COMP-3. *> 5 bytes
           05  WS-PACKED-DEC       PIC S9(7)V9(2) USAGE COMP-3. *> 5 bytes


      *----------------------------------------------------------------
      * USAGE COMP-5 (NATIVE BINARY / MACHINE INTEGER)
      * Like COMP/BINARY but respects the exact byte size; value range
      * is the full machine integer range (not limited by PIC digits).
      * Useful for interfacing with C or system calls.
      *----------------------------------------------------------------
       01  WS-COMP5-ITEMS.
           05  WS-C5-WORD          PIC S9(4)  USAGE COMP-5. *> 16-bit
           05  WS-C5-DWORD         PIC S9(9)  USAGE COMP-5. *> 32-bit
           05  WS-C5-QWORD         PIC S9(18) USAGE COMP-5. *> 64-bit


      *----------------------------------------------------------------
      * USAGE POINTER — stores a memory address (platform word size)
      *----------------------------------------------------------------
       01  WS-POINTER-ITEMS.
           05  WS-PTR              USAGE POINTER.
           05  WS-PROC-PTR         USAGE PROCEDURE-POINTER.


      *----------------------------------------------------------------
      * VALUE clause — various literals and figurative constants
      *----------------------------------------------------------------
       01  WS-INITIAL-VALUES.
           05  WS-ZERO-NUM         PIC 9(5)   VALUE ZERO.
           05  WS-ZERO-NUM2        PIC 9(5)   VALUE ZEROS.   *> synonym
           05  WS-BLANK-ALPHA      PIC X(10)  VALUE SPACES.
           05  WS-HIGH-BYTE        PIC X      VALUE HIGH-VALUES.
           05  WS-LOW-BYTE         PIC X      VALUE LOW-VALUES.
           05  WS-QUOTE-CHAR       PIC X      VALUE QUOTE.
           05  WS-PI               PIC S9V9(8) COMP-3
                                   VALUE 3.14159265.


      *----------------------------------------------------------------
      * LEVEL 77 — standalone elementary item (no group, no level 05)
      * Old style; prefer 01 elementaries in modern code
      *----------------------------------------------------------------
       77  WS-STANDALONE-CTR       PIC S9(5) COMP-3 VALUE ZERO.
       77  WS-STANDALONE-FLAG      PIC X     VALUE 'N'.
       77  WS-STANDALONE-MAX       PIC 9(9)  BINARY VALUE 999999999.


      *----------------------------------------------------------------
      * CONDITION NAMES — LEVEL 88
      * Attached to a parent data item; define named boolean conditions.
      * Test with:  IF WS-STATUS-OK  (instead of IF WS-STATUS = 'OK')
      *----------------------------------------------------------------
       01  WS-STATUS-CODE          PIC X(2).
           88  WS-STATUS-OK        VALUE 'OK'.
           88  WS-STATUS-WARN      VALUE 'WN'.
           88  WS-STATUS-ERROR     VALUE 'ER' 'EX'.   *> multiple values
           88  WS-STATUS-FATAL     VALUE 'FT'.

       01  WS-RETURN-CODE          PIC S9(4) COMP.
           88  WS-RC-SUCCESS       VALUE 0.
           88  WS-RC-WARNING       VALUE 1 THRU 4.    *> range
           88  WS-RC-ERROR         VALUE 8 THRU 99.
           88  WS-RC-ABEND         VALUE 100 THRU 999.

       01  WS-SWITCH               PIC X.
           88  WS-SWITCH-ON        VALUE 'Y' 'y' '1'. *> multi-value
           88  WS-SWITCH-OFF       VALUE 'N' 'n' '0'.


      *----------------------------------------------------------------
      * REDEFINES — two (or more) views over the same storage
      * The redefining item must immediately follow the redefined item.
      * Size of redefining item must NOT exceed size of redefined item.
      *----------------------------------------------------------------
       01  WS-DATE-NUMERIC         PIC 9(8).          *> YYYYMMDD as number
       01  WS-DATE-PARTS REDEFINES WS-DATE-NUMERIC.
           05  WS-DATE-YYYY        PIC 9(4).
           05  WS-DATE-MM          PIC 9(2).
           05  WS-DATE-DD          PIC 9(2).

      *  Another classic use: union of char and bytes
       01  WS-WORD-ALPHA           PIC X(4).
       01  WS-WORD-BYTES REDEFINES WS-WORD-ALPHA.
           05  WS-BYTE             PIC X OCCURS 4 TIMES.

      *  Numeric redefine: packed vs display over same bytes
       01  WS-RAW-BYTES            PIC X(5).
       01  WS-PACKED-VIEW REDEFINES WS-RAW-BYTES
                                   PIC S9(9) COMP-3.


      *----------------------------------------------------------------
      * RENAMES — LEVEL 66
      * Creates an alias spanning a contiguous range of fields
      * within an existing 01 group. Does NOT allocate new storage.
      * Syntax:  66  new-name  RENAMES first-field [THRU last-field].
      * Restrictions: cannot rename 01, 77, 66, 88 items;
      *               cannot rename items with OCCURS.
      *----------------------------------------------------------------
       01  WS-LEVEL-FULL.
           05  WS-REC-HEADER       PIC X(2).
           05  WS-REC-KEY          PIC X(8).
           05  WS-REC-AMOUNT       PIC S9(9)V99 COMP-3.
           05  WS-REC-DATE         PIC 9(8).
           05  WS-REC-FILLER       PIC X(10).

       66  WS-REC-KEY-AND-AMOUNT   RENAMES WS-REC-KEY
                                   THRU WS-REC-AMOUNT.
      *  Now WS-REC-KEY-AND-AMOUNT covers bytes 3..18 of WS-LEVEL-FULL

       66  WS-REC-EVERYTHING-ELSE  RENAMES WS-REC-AMOUNT
                                   THRU WS-REC-FILLER.


      *----------------------------------------------------------------
      * OCCURS — fixed-length tables (arrays)
      *----------------------------------------------------------------
       01  WS-FIXED-TABLE.
           05  WS-MONTH-ENTRY      OCCURS 12 TIMES
                                   INDEXED BY WS-MONTH-IDX.
               10  WS-MONTH-NAME   PIC X(9).
               10  WS-MONTH-DAYS   PIC 9(2).
               10  WS-MONTH-TOTAL  PIC S9(9)V99 COMP-3.


      *----------------------------------------------------------------
      * OCCURS DEPENDING ON (ODO) — variable-length table
      * The actual size is determined at run time by WS-ITEM-COUNT.
      *----------------------------------------------------------------
       01  WS-ITEM-COUNT           PIC S9(4) COMP VALUE 0.
       01  WS-VARIABLE-TABLE.
           05  WS-ITEM             OCCURS 1 TO 100 TIMES
                                   DEPENDING ON WS-ITEM-COUNT
                                   INDEXED BY WS-ITEM-IDX.
               10  WS-ITEM-CODE    PIC X(5).
               10  WS-ITEM-VALUE   PIC S9(7)V99 COMP-3.


      *----------------------------------------------------------------
      * OCCURS with ASCENDING/DESCENDING KEY + INDEXED BY
      * Enables the SEARCH ALL (binary search) verb
      *----------------------------------------------------------------
       01  WS-SORTED-TABLE.
           05  WS-SORTED-ENTRY     OCCURS 500 TIMES
                                   ASCENDING KEY IS WS-SORT-KEY
                                   INDEXED BY WS-SORT-IDX.
               10  WS-SORT-KEY     PIC X(10).
               10  WS-SORT-DATA    PIC X(40).


      *----------------------------------------------------------------
      * MULTI-DIMENSIONAL OCCURS (nested)
      *----------------------------------------------------------------
       01  WS-MATRIX.
           05  WS-ROW              OCCURS 10 TIMES
                                   INDEXED BY WS-ROW-IDX.
               10  WS-CELL         OCCURS 10 TIMES
                                   INDEXED BY WS-COL-IDX.
                   15  WS-CELL-VAL PIC S9(5)V99 COMP-3.


      *----------------------------------------------------------------
      * FILLER — anonymous / padding field (no name, cannot be referenced)
      *----------------------------------------------------------------
       01  WS-PADDED-LEVEL.
           05  WS-PR-TYPE          PIC X(2).
           05  FILLER              PIC X(3).   *> 3 bytes reserved/padding
           05  WS-PR-AMOUNT        PIC S9(9)V99 COMP-3.
           05  FILLER              PIC X(10)   VALUE SPACES.


      *----------------------------------------------------------------
      * JUSTIFIED RIGHT (JUST RIGHT) — right-aligns alphanumeric
      * Normally MOVE left-aligns; JUST RIGHT reverses that.
      *----------------------------------------------------------------
       01  WS-JUSTIFIED-ITEMS.
           05  WS-RIGHT-NAME       PIC X(20) JUSTIFIED RIGHT.


      *----------------------------------------------------------------
      * BLANK WHEN ZERO — display spaces instead of zeros for edited items
      *----------------------------------------------------------------
       01  WS-BLANK-ZERO-ITEMS.
           05  WS-BZ-AMOUNT        PIC ZZ,ZZ9.99 BLANK WHEN ZERO.


      *----------------------------------------------------------------
      * SYNCHRONIZED (SYNC) — aligns item to natural memory boundary
      * Avoids performance penalties on RISC/64-bit platforms.
      *----------------------------------------------------------------
       01  WS-SYNCED-ITEMS.
           05  WS-SYNC-SHORT       PIC S9(4)  BINARY SYNC.
           05  WS-SYNC-INT         PIC S9(9)  BINARY SYNC.


      *----------------------------------------------------------------
      * GLOBAL — item visible to all nested programs (contained programs)
      *----------------------------------------------------------------
       01  WS-GLOBAL-CTR           PIC S9(9) COMP-3 VALUE ZERO GLOBAL.


      *----------------------------------------------------------------
      * EXTERNAL — item shared across separately compiled programs
      * (same name + EXTERNAL in each program shares the same storage)
      *----------------------------------------------------------------
       01  WS-SHARED-STATUS        PIC X(2) VALUE SPACES EXTERNAL.


      *================================================================
      * Quick reference summary
      *
      *  LEVEL  PURPOSE
      *  -----  -------
      *  01     Top-level or standalone group/elementary
      *  02-49  Subordinate group or elementary items
      *  66     RENAMES alias (no new storage)
      *  77     Standalone elementary (legacy, avoid in new code)
      *  88     Condition name (boolean alias for a parent item)
      *
      *  PIC symbols:
      *   9  numeric digit
      *   A  alphabetic character (A-Z, space)
      *   X  alphanumeric (any character)
      *   S  sign (prefix)
      *   V  implied decimal point (no physical byte)
      *   P  implied scaling position (beyond decimal)
      *   Z  leading-zero suppression (edited)
      *   *  asterisk fill (edited)
      *   +/- explicit sign (edited)
      *   $  currency symbol (edited, floating or fixed)
      *   .  actual decimal point (edited)
      *   ,  comma insertion (edited)
      *   /  slash insertion (edited)
      *   B  blank insertion (edited)
      *   0  zero insertion (edited)
      *   CR/DB credit/debit suffix (edited)
      *
      *  USAGE clauses:
      *   DISPLAY      default; human-readable zoned decimal / char
      *   BINARY       two's-complement integer (= COMP on most systems)
      *   COMP         synonym for BINARY (platform-dependent historically)
      *   COMP-1       single-precision float (4 bytes, no PIC)
      *   COMP-2       double-precision float (8 bytes, no PIC)
      *   COMP-3       packed decimal (BCD, 2 digits/byte + sign nibble)
      *   COMP-5       native binary; value range = full machine integer
      *   POINTER      memory address
      *   PROCEDURE-POINTER  address of a procedure/function
      *================================================================

       PROCEDURE DIVISION.
           STOP RUN.
