#!/bin/bash

#
# Copyright (C) 2024 Nethesis S.r.l.
# SPDX-License-Identifier: GPL-3.0-or-later
#

set -e

PBHOME="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && cd .. && pwd )"

cd "$PBHOME"

CLASSPATH="lib/*:classes"
export PATH CLASSPATH

if [ -n $PECBRIDGE_FROM_ADDRESS ]; then
    from_address="fromAddress=\"$PECBRIDGE_FROM_ADDRESS\""
else
    from_address=""
fi

if [ -n $PECBRIDGE_NOTIFY_OWNER ]; then
    notify_owner="notifyOwner=\"$PECBRIDGE_NOTIFY_OWNER\""
else
    notify_owner=""
fi

# Expand the Pecbridge configuration file template:
sed -e "s/WEBAPP_API_TOKEN/${WEBAPP_API_TOKEN:?}/" \
    -e "s/PECBRIDGE_FROM_ADDRESS/$from_address/" \
    -e "s/PECBRIDGE_NOTIFY_OWNER/$notify_owner/" \
    ${PECBRIDGE_ADMIN_MAIL:+-e "1 s/sonicle-pec-bridge /sonicle-pec-bridge adminMail=\"${PECBRIDGE_ADMIN_MAIL}\" /"} \
    etc/config.xml.template > etc/config.xml

# Start the Pecbridge process
exec java com.sonicle.pecbridge.Main etc/config.xml
