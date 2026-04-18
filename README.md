# nginx-header-poc

Proof of concept: nginx reverse proxy that routes based on the `x-npe-env` request header.

## Architecture

```
Client -> nginx (port 8080) -> http-server (port 80)
```

**nginx** — reverse proxy handling `/mf/*` and `/ms/*` routes. When the `x-npe-env`
header is present, it tries `/{env}/{path}` on the backend first; on 404 it falls
back to `/{path}`.

**http-server** — simple backend with static responses:

| Endpoint   | Response        |
|------------|-----------------|
| `/`        | `Fallback Host` |
| `/custom`  | `custom 1`      |
| `/custom2` | `custom 2`      |

## Usage

```sh
docker compose up -d
```

## Curl Tests

### Basic routing (no header)

```sh
# /mf -> backend /
curl -s http://localhost:8080/mf
# => Fallback Host

# /mf/custom -> backend /custom
curl -s http://localhost:8080/mf/custom
# => custom 1

# /mf/custom2 -> backend /custom2
curl -s http://localhost:8080/mf/custom2
# => custom 2

# /ms prefix works the same way
curl -s http://localhost:8080/ms/custom
# => custom 1
```

### With `x-npe-env` header (env path does NOT exist on backend)

When the env-prefixed path 404s, nginx falls back to the original path.

```sh
# Tries /staging/custom -> 404 -> falls back to /custom
curl -s -H "x-npe-env: staging" http://localhost:8080/mf/custom
# => custom 1

# Tries /staging/custom2 -> 404 -> falls back to /custom2
curl -s -H "x-npe-env: staging" http://localhost:8080/mf/custom2
# => custom 2

# Tries /staging/ -> 404 -> falls back to /
curl -s -H "x-npe-env: staging" http://localhost:8080/mf
# => Fallback Host
```

### With `x-npe-env` header (env path EXISTS on backend)

To test this, add a route to `http-server/default.conf`:

```nginx
location = /staging/custom {
    default_type text/plain;
    return 200 'custom 1 from staging env';
}
```

Then restart and test:

```sh
docker compose restart

# Tries /staging/custom -> 200, served directly
curl -s -H "x-npe-env: staging" http://localhost:8080/mf/custom
# => custom 1 from staging env

# /staging/custom2 still doesn't exist -> falls back to /custom2
curl -s -H "x-npe-env: staging" http://localhost:8080/mf/custom2
# => custom 2
```

## Teardown

```sh
docker compose down
```
