google_ics_fix
==============

Simple php ics rewriter to fix Google ICS weirdness.

## Fixes

1. Edited Repeating Events where not all event occurrences have been edited. Google incorrectly adds an occurrence with the old data starting at the same time as the new edited occurrence. This creates a duplicate event and needs to be weeded out.
