<VirtualHost *:{{HTTP_PORT}}>
    ServerName {{SERVER_NAME}}
    DocumentRoot "{{ROOT}}"

    <Directory "{{ROOT}}">
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted

        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{{PHP_FPM_SOCK}}|fcgi://localhost/"
    </FilesMatch>

    # Security Headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "no-referrer-when-downgrade"
    Header always set Permissions-Policy "interest-cohort=()"

    ErrorLog ${APACHE_LOG_DIR}/vmforge_error.log
    CustomLog ${APACHE_LOG_DIR}/vmforge_access.log combined
</VirtualHost>
