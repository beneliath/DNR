GO ?= go
PLUGIN_ID := org.moed.mattermost
PLUGIN_VERSION := 0.4.0
BUNDLE_NAME := $(PLUGIN_ID)-$(PLUGIN_VERSION).tar.gz
SERVER_DIST := server/dist
WEBAPP_DIST := webapp/dist
BUNDLE_ROOT := dist/$(PLUGIN_ID)

.PHONY: all test vet server webapp bundle dist clean

all: dist

test:
	$(GO) test ./...

vet:
	$(GO) vet ./...

server:
	rm -rf $(SERVER_DIST)
	mkdir -p $(SERVER_DIST)
	cd server && CGO_ENABLED=0 GOOS=linux GOARCH=amd64 $(GO) build -trimpath -ldflags="-s -w" -o dist/plugin-linux-amd64
	cd server && CGO_ENABLED=0 GOOS=linux GOARCH=arm64 $(GO) build -trimpath -ldflags="-s -w" -o dist/plugin-linux-arm64
	cd server && CGO_ENABLED=0 GOOS=darwin GOARCH=amd64 $(GO) build -trimpath -ldflags="-s -w" -o dist/plugin-darwin-amd64
	cd server && CGO_ENABLED=0 GOOS=darwin GOARCH=arm64 $(GO) build -trimpath -ldflags="-s -w" -o dist/plugin-darwin-arm64
	cd server && CGO_ENABLED=0 GOOS=windows GOARCH=amd64 $(GO) build -trimpath -ldflags="-s -w" -o dist/plugin-windows-amd64.exe

webapp:
	rm -rf $(WEBAPP_DIST)
	mkdir -p $(WEBAPP_DIST)
	../node_modules/.bin/esbuild webapp/src/index.jsx --bundle --minify --legal-comments=none --loader:.jsx=jsx --outfile=$(WEBAPP_DIST)/main.js

bundle: server webapp
	rm -rf $(BUNDLE_ROOT)
	mkdir -p $(BUNDLE_ROOT)/server $(BUNDLE_ROOT)/webapp $(BUNDLE_ROOT)/assets
	cp plugin.json $(BUNDLE_ROOT)/plugin.json
	cp -R $(SERVER_DIST) $(BUNDLE_ROOT)/server/
	cp -R $(WEBAPP_DIST) $(BUNDLE_ROOT)/webapp/
	cp assets/moed-icon.svg $(BUNDLE_ROOT)/assets/moed-icon.svg
	cd dist && COPYFILE_DISABLE=1 tar -czf $(BUNDLE_NAME) $(PLUGIN_ID)
	shasum -a 256 dist/$(BUNDLE_NAME)

dist: test vet bundle

clean:
	rm -rf $(SERVER_DIST) $(WEBAPP_DIST) dist
