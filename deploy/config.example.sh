# Deployment target for the MaizeGDB development instance.
#
# Copy to deploy/config.local.sh and fill in the real values. That file is
# gitignored so host names and server paths stay out of the repository.
#
#   cp deploy/config.example.sh deploy/config.local.sh
#
# HOST    an ssh alias defined in ~/.ssh/config, not a bare hostname
# WEBROOT absolute path to the instance web root on that host

HOST="development-server"
WEBROOT="/path/to/webroot"
