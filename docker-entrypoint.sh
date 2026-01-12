#!/bin/bash
set -e

# Disable all possible MPMs (ignore errors)
a2dismod mpm_event 2>/dev/null || true
a2dismod mpm_worker 2>/dev/null || true
a2dismod mpm_prefork 2>/dev/null || true

# Enable only prefork (required for mod_php in php:*-apache)
a2enmod mpm_prefork

exec apache2-foreground
