# ui

## Project setup
```
yarn install
```

Or using a container:
```
podman build -t ns8-webtop-dev .
```

### Compiles and hot-reloads for development
```
yarn serve
```

Or using a container:
```
podman run -ti -v $(pwd):/app:Z --name ns8-webtop --replace ns8-webtop-dev watch
```

Sync to your NS8 installation:
```
watch -n 1 rsync -avz --delete dist/* root@YOUR_NS8_MACHINE:/var/lib/nethserver/cluster/ui/apps/webtop1/
```

### Compiles and minifies for production
```
yarn build
```

### Lints and fixes files
```
yarn lint
```

### Customize configuration
See [Configuration Reference](https://cli.vuejs.org/config/).
