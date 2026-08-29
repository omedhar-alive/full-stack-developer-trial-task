# syntax=docker/dockerfile:1

# ---- build ----
FROM golang:1.26 AS build
WORKDIR /src

# go.sum is absent until the first external dependency is added (phase 2);
# the glob keeps this COPY valid either way.
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
