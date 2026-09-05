#!/bin/sh
#
# Start Nouveau, the Lucene-based full-text search service that backs the
# ?q= search endpoint.
#
# Nouveau ships inside the CouchDB application bundle but is a separate Java
# process: the CouchDB.app menu-bar item does not start it. Without it CouchDB
# answers full-text queries with {"error":"service unavailable"} and search
# returns nothing, even though every other part of the site works.
#
# Usage:  ./start-nouveau.sh
#
# It listens on 127.0.0.1:5987, which is where CouchDB expects it (see
# http://127.0.0.1:5984/_node/_local/_config/nouveau). The index lives under
# data/nouveau in the bundle and persists between runs, so a restart is quick.

set -e

COUCH_HOME="/Applications/Apache CouchDB.app/Contents/Resources/couchdbx-core"
JAR="$COUCH_HOME/nouveau/lib/nouveau-1.0-SNAPSHOT.jar"
CONF="$COUCH_HOME/etc/nouveau.yaml"
LOG="${TMPDIR:-/tmp}/nouveau.log"

if [ ! -f "$JAR" ]; then
	echo "Nouveau jar not found at:" >&2
	echo "  $JAR" >&2
	echo "Is Apache CouchDB.app installed?" >&2
	exit 1
fi

if curl -s -o /dev/null -m 2 http://127.0.0.1:5987/; then
	echo "Nouveau is already running on 127.0.0.1:5987"
	exit 0
fi

# rootDir in nouveau.yaml is relative, so this must run from the bundle root.
cd "$COUCH_HOME"

echo "Starting Nouveau (logging to $LOG)"
nohup java -jar "$JAR" server "$CONF" > "$LOG" 2>&1 &

printf "Waiting for 127.0.0.1:5987 "
i=0
while [ $i -lt 60 ]; do
	if curl -s -o /dev/null -m 2 http://127.0.0.1:5987/; then
		echo
		echo "Nouveau is up."
		exit 0
	fi
	printf "."
	sleep 1
	i=$((i + 1))
done

echo
echo "Nouveau did not come up within 60s; see $LOG" >&2
exit 1
