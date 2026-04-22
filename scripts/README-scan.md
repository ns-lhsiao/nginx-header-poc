# Orphan Steerexcep RIS Scanner

Scans all tenants (or a single tenant) for steering exception entries in RIS
whose `setting_id` no longer exists in the MariaDB `bypass_settings_v2` table.

Related: ENG-961244, ENG-914587, CM-212286

## Prerequisites

Run from inside a webui pod (`kubectl exec`). The script uses:
- `helper_functions.php` from the webui scripts directory (`/var/www/scripts/`)
- MySQL credentials from `/opt/ns/common/remote/mysql`
- RIS internal API at `http://referential-integrity-service.swg-mp`

## Usage

```bash
# Copy into the webui pod
kubectl cp scanOrphanSteerexcepRis.php <namespace>/<pod>:/var/www/scripts/

# Shell into the pod
kubectl exec -it -n <namespace> <pod> -- bash

# Scan all tenants
cd /var/www/scripts
php scanOrphanSteerexcepRis.php

# Scan a single tenant
php scanOrphanSteerexcepRis.php -t 23046

# Custom output directory
php scanOrphanSteerexcepRis.php -o /tmp/orphan_scan
```

## Output

- `orphan_steerexcep_output/<tenant_id>.txt` — per-tenant report (only for tenants with orphans)
- `orphan_steerexcep_output/summary.csv` — CSV with status for all tenants

## Retrieving results

```bash
kubectl cp <namespace>/<pod>:/var/www/scripts/orphan_steerexcep_output ./orphan_steerexcep_output
```
