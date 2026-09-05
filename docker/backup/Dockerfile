# The backup job. Nothing but the Postgres client tools and two scripts — it deliberately
# shares no image, no code and no PHP runtime with the application, so a broken deploy of
# the app cannot stop the backups.
FROM postgres:16-alpine

COPY docker/backup/backup.sh docker/backup/restore.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/restore.sh

# Created and owned here so a fresh named volume inherits the ownership: Docker copies the
# image's directory, permissions included, into an empty volume. A *bind* mount does not
# work that way — the host directory's own ownership wins — which is why backup.sh checks
# that it can write before it does anything else.
RUN mkdir -p /backups && chown postgres:postgres /backups

# Never run as root: the dumps carry every rating and review in the library.
USER postgres

CMD ["backup.sh"]
