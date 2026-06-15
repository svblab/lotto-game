# Manual Verification: EPIC-0.8 Database Initialization
## Objective
Verify that `init_db.php` safely creates the database structure, 
triggers system pragmas, provisions default indexes, and safely 
provisions the primary administrative account exactly once.
## Verification Steps
### 1. Fresh DB Provisioning Test
1. Purge any current active local database allocations: ```bash
   rm -f game.db
