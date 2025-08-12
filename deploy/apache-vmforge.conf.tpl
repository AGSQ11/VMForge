<VirtualHost *:{{HTTP_PORT}}>
    ServerName {{SERVER_NAME}}
    DocumentRoot "{{ROOT}}"

    <Directory "{{ROOT}}">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{{PHP_FPM_SOCK}}|fcgi://localhost/"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/vmforge_error.log
    CustomLog ${APACHE_LOG_DIR}/vmforge_access.log combined
</VirtualHost>
