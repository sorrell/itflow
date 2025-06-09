#!/bin/ash

sed -i "s/^Listen.*/Listen $ITFLOW_PORT/g" /etc/apache2/httpd.conf
rm -rf /var/www/localhost/htdocs/index.html
# if itflow is not downloaded, perform the download after the volume mounting process within dockerfile is complete.
if [[ -f /var/www/localhost/htdocs/index.php ]]; then 
    cd /var/www/localhost/htdocs
    # git fetch
else
    git clone --branch $ITFLOW_REPO_BRANCH https://$ITFLOW_REPO /var/www/localhost/htdocs
fi

git config --global --add safe.directory /var/www/localhost/htdocs

# Verify permissions of itflow git repository
chown -R apache:apache /var/www/localhost/htdocs

# This updates the config.php file once initialization through setup.php has completed
if [[ -f /var/www/localhost/htdocs/config.php ]]; then 
    # Company Name
    sed -i "s/\$config_app_name.*';/\$config_app_name = '$ITFLOW_NAME';/g" /var/www/localhost/htdocs/config.php

    # MariaDB Host
    sed -i "s/\$dbhost.*';/\$dbhost = '$ITFLOW_DB_HOST';/g" /var/www/localhost/htdocs/config.php

    # Database Password
    sed -i "s/\$dbpassword.*';/\$dbpassword = '$ITFLOW_DB_PASS';/g" /var/www/localhost/htdocs/config.php

    # Base URL
    sed -i "s/\$config_base_url.*';/\$config_base_url = '$ITFLOW_URL';/g" /var/www/localhost/htdocs/config.php

    # Repo Branch
    sed -i "s/\$repo_branch.*';/\$repo_branch = '$ITFLOW_REPO_BRANCH';/g" /var/www/localhost/htdocs/config.php
    
    find /var/www/localhost/htdocs -type d -exec chmod 775 {} \;
    find /var/www/localhost/htdocs -type f -exec chmod 664 {} \;
    chmod 640 /var/www/localhost/htdocs/config.php
else 
    sleep 10
    httpd -D FOREGROUND &
    HTTPD_PID=$!

    # Wait for httpd to start up completely
    sleep 2

    php84 /var/www/localhost/htdocs/docker/auto_setup.php
    sed -i 's/$config_https_only = .*;/$config_https_only = FALSE;/g' /var/www/localhost/htdocs/config.php

    # Stop httpd gracefully
    kill $HTTPD_PID

    # Wait for httpd to terminate
    wait $HTTPD_PID

fi

# Start Cron

crond &

# Execute the command in the dockerfile's CMD
exec "$@"