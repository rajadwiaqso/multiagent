# Usage

## Setup

Set the target workspace path in `.env`:

```env
TARGET_WORKSPACE_PATH="C:\Users\Raja Dwi Aqso\Documents\rexmarket"
```

Run migrations and seed the default agents:

```bash
php artisan migrate --seed
```

Initialize the RexMarket workspace record:

```bash
php artisan agents:workspace:init
```

## First Mission

Create a mission and agent branch:

```bash
php artisan agents:mission "Tambah fitur wishlist produk" --branch=agent/wishlist-product
```

Run one step:

```bash
php artisan agents:step 1
```

Run until completion, stop, failure, max steps, or approval:

```bash
php artisan agents:run 1 --until-approval
```

Inspect target diff:

```bash
php artisan agents:diff 1
```

View a full report:

```bash
php artisan agents:report 1
```

Pause or stop:

```bash
php artisan agents:pause 1
php artisan agents:stop 1
```

## Notes

- `FakeLlmClient` is a placeholder and does not call any real model provider.
- The implementer stub does not write target code yet.
- All target code changes must go through workspace services and must be on an `agent/*` branch.
- The system does not push, merge, deploy, or store API keys.
