# 0004 — No RUN, and no patient identity of any kind

**Status:** accepted (supersedes the earlier plan to capture the RUN for one day)

A turno is a servicio, a preferente flag, a sequence and timestamps. The totem asks two questions: which servicio, and whether the patient qualifies for attention preferente.

**Why.** Some patients have no RUN; asking for it is slower and the keypad step was the longest in the flow. Not collecting it removes the data, so there is nothing to purge, mask or leak. Duplicate-turno detection is lost; see the threat model for the bounds that replace it.
