#!/bin/bash

# Terminate on error
set -e
cleanup_list=()
trap 'rm -rf "${cleanup_list[@]}"' EXIT

# Prepare variables for later use
images=()
# The image will be pushed to GitHub container registry
repobase="${REPOBASE:-ghcr.io/mrmarkuz}"
#Get WebTop version
webtop_version=$(cat ${PWD}/webtop5-build/VERSION)

# Download of external deps and CHECKSUM verification:
if ! compgen -G "pecbridge-*.tar.gz"; then
    curl --netrc --fail -O "https://www.sonicle.com/nethesis/commercial/pecbridge/pecbridge-5.4.8.tar.gz"
fi
sha256sum -c CHECKSUM

# Reuse existing webtopbuilder container, to speed up builds
if ! buildah containers --format "{{.ContainerName}}" | grep -q webtopbuilder; then
    echo "Pulling maven runtime..."
    buildah from --name webtopbuilder-tmp docker.io/library/maven:3.6-openjdk-8
    buildah run webtopbuilder-tmp  sed -i 's/deb.debian.org\|security.debian.org/archive.debian.org/' /etc/apt/sources.list
    buildah run webtopbuilder-tmp  apt-get  update
    buildah run webtopbuilder-tmp  apt-get  install -y  nodejs make
    buildah commit --rm webtopbuilder-tmp webtopbuilder-image
    buildah from --name webtopbuilder \
	    -v "${PWD}/webtop5-build:/webtop5-build:z" \
	    localhost/webtopbuilder-image
fi

if [ ! -e ${PWD}/webtop5-build/webtop-webapp-$webtop_version.war ]; then
    buildah run webtopbuilder sh -c "mkdir -p ~/.m2"
    buildah run webtopbuilder sh -c "cp webtop5-build/settings.xml  ~/.m2/"
    buildah run webtopbuilder sh -c "cd webtop5-build/ && ./prep-sources"
fi

if [ ! -e ${PWD}/webtop5-build/ListTimeZones.class ]; then
    buildah run webtopbuilder sh -c "cd webtop5-build/ && javac ListTimeZones.java"
fi

if [ ! -e ${PWD}/webtop5-build/WebtopPassEncode.class ]; then
    buildah run webtopbuilder sh -c "cd webtop5-build/ && javac WebtopPassEncode.java"
fi

jcharset_tmp_dir=$(mktemp -d)
cleanup_list+=("${jcharset_tmp_dir}")
(
    cd "${jcharset_tmp_dir}"
    echo "Downloading jcharset-2.0.jar..."
    curl -O https://distfeed.nethserver.org/webtop/jcharset-2.0.jar
)

webapp_tmp_dir=$(mktemp -d)
cleanup_list+=("${webapp_tmp_dir}")
(
    mkdir -p "${webapp_tmp_dir}/webtop/"
    python -mzipfile -e  ${PWD}/webtop5-build/webtop-webapp-$webtop_version.war "${webapp_tmp_dir}/webtop/"
)

pecbridge_tmp_dir=$(mktemp -d)
cleanup_list+=("${pecbridge_tmp_dir}")
tar -C "${pecbridge_tmp_dir}" -x -v -z -f pecbridge-*.tar.gz

#Create webtop-webapp container
reponame="webtop-webapp"
container=$(buildah from docker.io/library/tomcat:9.0.107-jre8)
buildah add ${container} ${webapp_tmp_dir}/webtop /usr/local/tomcat/webapps/webtop/
buildah add ${container} ${PWD}/webtop5-build/context.xml /usr/local/tomcat/webapps/webtop/META-INF/context.xml
buildah add ${container} ${PWD}/webtop5-build/webtop-login/ /usr/local/tomcat/webapps/webtop/WEB-INF/classes/
buildah add ${container} ${jcharset_tmp_dir}/jcharset-2.0.jar /opt/java/openjdk/lib/ext/
buildah add ${container} ${PWD}/webtop5-build/ListTimeZones.class /usr/share/webtop/
buildah add ${container} ${PWD}/webtop5-build/WebtopPassEncode.class /usr/share/webtop/
buildah add ${container} ${PWD}/zfaker/wrappers/php /usr/share/webtop/bin/php
buildah add ${container} ${PWD}/zfaker/wrappers/z-push-admin-wapper /usr/share/webtop/bin/z-push-admin-wrapper
buildah add ${container} ${pecbridge_tmp_dir}/pecbridge /usr/share/pecbridge
buildah add ${container} ${PWD}/webapp/ /
# Commit the image
buildah commit --rm "${container}" "${repobase}/${reponame}"

# Append the image URL to the images array
images+=("${repobase}/${reponame}")

#Create webtop-postgres container
reponame="webtop-postgres"
container=$(buildah from docker.io/library/postgres:17.5)
buildah add ${container} ${PWD}/webtop5-build/sql-scripts-$webtop_version.tar.gz /docker-entrypoint-initdb.d/
buildah add ${container} ${PWD}/postgres/data /docker-entrypoint-initdb.d/data
buildah add ${container} ${PWD}/postgres/postgres /docker-entrypoint-initdb.d/postgres
buildah add ${container} ${PWD}/postgres/webtop-init.sh /docker-entrypoint-initdb.d/
buildah run ${container} chmod 755 /docker-entrypoint-initdb.d
buildah config -e POSTGRES_DB=webtop5 ${container}
# Commit the image
buildah commit --rm "${container}" "${repobase}/${reponame}"

# Append the image URL to the images array
images+=("${repobase}/${reponame}")


#Create webtop-apache container
reponame="webtop-apache"
container=$(buildah from docker.io/library/httpd:2.4)
buildah add ${container} ${PWD}/apache/ /
buildah add ${container} ${PWD}/webtop5-build/webtop-dav-server-$webtop_version.tgz /usr/share/webtop/webdav/
buildah add ${container} ${PWD}/webtop5-build/webtop-eas-server-$webtop_version.tgz /usr/share/webtop/z-push/
buildah add ${container} ${PWD}/zfaker/src/ /usr/share/webtop/zfacker/
buildah config -e APACHE_HTTP_PORT_NUMBER=8081 ${container}
# Commit the image
buildah commit --rm "${container}" "${repobase}/${reponame}"

# Append the image URL to the images array
images+=("${repobase}/${reponame}")


#Create webtop-webdav container
reponame="webtop-webdav"
container=$(buildah from docker.io/library/php:7.3-fpm-alpine)
buildah add ${container} ${PWD}/webtop5-build/webtop-dav-server-$webtop_version.tgz /usr/share/webtop/webdav/
buildah run ${container} sh -c "mv \$PHP_INI_DIR/php.ini-production \$PHP_INI_DIR/php.ini"
# Commit the image
buildah commit --rm "${container}" "${repobase}/${reponame}"

# Append the image URL to the images array
images+=("${repobase}/${reponame}")

#Create webtop-z-push container
reponame="webtop-z-push"
container=$(buildah from docker.io/library/php:7.3-fpm-alpine)
buildah copy --from=docker.io/mlocati/php-extension-installer:1.5.37 ${container} /usr/bin/install-php-extensions /usr/local/bin/
buildah run ${container} sh -c "install-php-extensions imap"
buildah add ${container} ${PWD}/webtop5-build/webtop-eas-server-$webtop_version.tgz /usr/share/webtop/z-push/
buildah add ${container} ${PWD}/zfaker/src/ /usr/share/webtop/zfacker/
buildah run ${container} sh -c "mv \$PHP_INI_DIR/php.ini-production \$PHP_INI_DIR/php.ini"
buildah run ${container} sh -c "mkdir -p /var/log/z-push/state && chown www-data:www-data /var/log/z-push /var/log/z-push/state"
buildah run ${container} sh -c "sed -i 's/pm.max_children = 5/pm.max_children = 50/' /usr/local/etc/php-fpm.d/www.conf"
buildah run ${container} sh -c "sed -i 's/pm.start_servers = 2/pm.start_servers = 5/' /usr/local/etc/php-fpm.d/www.conf"
buildah run ${container} sh -c "sed -i 's/pm.min_spare_servers = 1/pm.min_spare_servers = 5/' /usr/local/etc/php-fpm.d/www.conf"
buildah run ${container} sh -c "sed -i 's/pm.max_spare_servers = 3/pm.max_spare_servers = 35/' /usr/local/etc/php-fpm.d/www.conf"
buildah run ${container} sh -c "sed -i 's/;php_admin_value\[memory_limit\] = 32M/php_admin_value[memory_limit] = 512M/' /usr/local/etc/php-fpm.d/www.conf"
buildah run ${container} sh -c "sed -i 's/9000/9001/' /usr/local/etc/php-fpm.d/zz-docker.conf"
# Commit the image
buildah commit --rm "${container}" "${repobase}/${reponame}"

# Append the image URL to the images array
images+=("${repobase}/${reponame}")

reponame="webtop-phonebook"
container=$(buildah from docker.io/library/php:8-cli-alpine)
buildah copy --from=docker.io/mlocati/php-extension-installer:2.7.28 ${container} /usr/bin/install-php-extensions /usr/local/bin/
buildah run ${container} sh -c "install-php-extensions pgsql mysqli"
buildah add ${container} ${PWD}/phonebook/webtop2phonebook.php /usr/share/phonebooks/scripts/
buildah add ${container} ${PWD}/phonebook/pbook2webtop.php /usr/share/phonebooks/post_scripts/
buildah add ${container} ${PWD}/phonebook/start.sh /usr/share/phonebooks/
buildah config --cmd /usr/share/phonebooks/start.sh ${container}
# Commit the image
buildah commit --rm "${container}" "${repobase}/${reponame}"

# Append the image URL to the images array
images+=("${repobase}/${reponame}")

# Configure the image name
reponame="webtop"

# Create a new empty container image
container=$(buildah from scratch)

# Reuse existing nodebuilder-webtop container, to speed up builds
if ! buildah containers --format "{{.ContainerName}}" | grep -q nodebuilder-webtop; then
    echo "Pulling NodeJS runtime..."
    buildah from --name nodebuilder-webtop -v "${PWD}:/usr/src:Z" docker.io/library/node:22.16.0-slim
fi

echo "Build static UI files with node..."
buildah run --env="NODE_OPTIONS=--openssl-legacy-provider" nodebuilder-webtop sh -c "cd /usr/src/ui && yarn install && yarn build"

# Add imageroot directory to the container image
buildah add "${container}" imageroot /imageroot
buildah add "${container}" ui/dist /ui
# Setup the entrypoint, ask to reserve one TCP port with the label and set a rootless container
buildah config --entrypoint=/ \
    --label="org.nethserver.authorizations=traefik@node:routeadm mail@any:mailadm cluster:accountconsumer nethvoice@any:pbookreader" \
    --label="org.nethserver.tcp-ports-demand=1" \
    --label="org.nethserver.rootfull=0" \
    --label="org.nethserver.min-from=1.4.4" \
    --label="org.nethserver.images=${repobase}/webtop-webapp:${IMAGETAG:-latest} \
    ${repobase}/webtop-postgres:${IMAGETAG:-latest} \
    ${repobase}/webtop-apache:${IMAGETAG:-latest} \
    ${repobase}/webtop-webdav:${IMAGETAG:-latest} \
    ${repobase}/webtop-z-push:${IMAGETAG:-latest} \
    ${repobase}/webtop-phonebook:${IMAGETAG:-latest}" \
    "${container}"
# Commit the image
buildah commit "${container}" "${repobase}/${reponame}"

# Append the image URL to the images array
images+=("${repobase}/${reponame}")

#
# NOTICE:
#
# It is possible to build and publish multiple images.
#
# 1. create another buildah container
# 2. add things to it and commit it
# 3. append the image url to the images array
#

#
# Setup CI when pushing to Github. 
# Warning! docker::// protocol expects lowercase letters (,,)
if [[ -n "${CI}" ]]; then
    # Set output value for Github Actions
    echo "images=${images[*],,}" >> "$GITHUB_OUTPUT"
else
    # Just print info for manual push
    printf "Publish the images with:\n\n"
    for image in "${images[@],,}"; do printf "  buildah push %s docker://%s:%s\n" "${image}" "${image}" "${IMAGETAG:-latest}" ; done
    printf "\n"
fi
