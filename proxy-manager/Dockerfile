# syntax=docker/dockerfile:1

# ---- build ----
FROM golang:1.26 AS build
WORKDIR /src

# The service has no external dependencies, so there is no go.sum. The glob
# keeps this COPY valid if one is ever added.
COPY go.mod go.su[m] ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/proxy-manager .

# ---- runtime ----
FROM alpine:3.22
RUN apk add --no-cache ca-certificates \
    && adduser -D -u 10001 app
USER app

WORKDIR /app
COPY --from=build /out/proxy-manager /usr/local/bin/proxy-manager
# PROXY_POOL_FILE defaults to /app/proxies.json; ship the seed at that path.
COPY proxies.json /app/proxies.json

EXPOSE 8080
ENTRYPOINT ["proxy-manager"]
