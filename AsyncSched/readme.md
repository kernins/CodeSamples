## Async task scheduler daemon with AMQP-based IPC.

This was written for a specific case of handling a pretty high flow of incoming files containing some structured data to be processed/imported/aggregated.
Many of those files had cross-relations/dependencies and required appropriate scheduling to be able to be properly handled in parallel.
Moreover, the only way to discover those relations was to analyze contained data. 
So it turned out to be a 2-stage pipeline classic multi-worker model with horizontal scaling potential.
