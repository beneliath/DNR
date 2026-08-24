#!/bin/sh

# The production INI disables process execution for every SAPI. These two
# interactive maintenance commands restore only shell_exec so PHP can invoke
# stty to hide the password; all other process functions remain unavailable.
case ${0##*/} in
    dnr-create-admin)
        password_script=/opt/dnr/bin/create_admin.php
        ;;
    dnr-set-password)
        password_script=/opt/dnr/bin/set_password.php
        ;;
    *)
        echo 'Unknown DNR password command.' >&2
        exit 64
        ;;
esac

# Recover terminal echo even if the PHP child is interrupted mid-prompt.
trap 'stty echo 2>/dev/null || true' EXIT HUP INT TERM
php -d 'disable_functions=exec,passthru,system,proc_open,popen' "$password_script" "$@"
exit_status=$?
exit "$exit_status"
