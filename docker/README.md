# docker/

Monorepo-level container assets and notes. Each service's own build files live
with the service:

| Service  | Image source                          | Dockerfile              |
|----------|---------------------------------------|-------------------------|
| mysql    | `mysql:8.4` (unmodified)              | —                       |
| backend  | `dunglas/frankenphp:1-php8.4`         | `backend/Dockerfile`    |
| worker   | same image as backend, command only  | `backend/Dockerfile`    |
| proxy    | `golang:1.26` → `alpine:3.22`         | `proxy-manager/Dockerfile` |
| frontend | `node:22-alpine`, standalone output  | `frontend/Dockerfile`   |

`docker-compose.yml` is at the repo root so `docker compose up --build` works
from a fresh clone with no `-f` flag.

Ports and hostnames are frozen in `../CONTRACTS.md` section 1. Environment
variable names are frozen in section 2.
