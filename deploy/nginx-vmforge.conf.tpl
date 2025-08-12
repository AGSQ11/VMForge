\
    # VMForge — nginx vhost
    server {
      listen {{HTTP_PORT}} default_server;
      server_name {{SERVER_NAME}};
      access_log /var/log/nginx/vmforge_access.log;
      error_log  /var/log/nginx/vmforge_error.log;

      root {{ROOT}};
      index index.php index.html;

      location /assets/ {
        try_files $uri =404;
      }

      location / {
        try_files $uri /index.php$is_args$args;
      }

      location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{{PHP_FPM_SOCK}};
      }

      # Security headers
      add_header X-Content-Type-Options "nosniff" always;
      add_header X-Frame-Options "SAMEORIGIN" always;
      add_header Referrer-Policy "no-referrer-when-downgrade" always;
      add_header Permissions-Policy "interest-cohort=()" always;

      client_max_body_size 64m;
    }
