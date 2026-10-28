# Update the package repository information
sudo apt-get update

# Install Supervisor package automatically
sudo apt-get install supervisor -y

# Start the Supervisor service immediately
sudo systemctl start supervisor

# Enable Supervisor to automatically start on system boot
sudo systemctl enable supervisor

# Check the current status of the Supervisor service
sudo systemctl status supervisor

# Create the symlink
ln -s /home/castilo-admin/htdocs/admin.castilo.co.uk/server/master.conf /etc/supervisor/conf.d/castilo-master.conf


# Read and apply the new configuration
sudo supervisorctl reread
sudo supervisorctl update

# Start all services in the group
sudo supervisorctl start laravel-services:*

# Restart all services in the group
sudo supervisorctl restart laravel-services:*



# Check 
# Check the status of the services
sudo supervisorctl status

# Restart all services in the group
sudo supervisorctl restart laravel-services:*




*****************************
For setting reverb
Add this block after  include /etc/nginx/global_settings;

  
  # Laravel Reverb WebSocket proxy configuration
  location ~ ^/(app|apps) {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_read_timeout 60m;
    proxy_send_timeout 60m;
    
    # Ensure this port matches your REVERB_SERVER_PORT in the .env file
    proxy_pass http://127.0.0.1:8082;
  }


  ***********************************
  For setting deeplink 
  Add this block just after if ($scheme != "https") {
    rewrite ^ https://$host$request_uri permanent;
  } 


  # Deeplink configuration for apple
  location = /.well-known/apple-app-site-association {
    default_type application/json;
    auth_basic off;
    allow all;
    
    # Try to serve the static file first. 
    # If it doesn't exist, forward the request to the main application via Varnish.
    try_files $uri @app;
  }
  
  # Deeplink configuration for google
  location = /.well-known/assetlinks.json {
    default_type application/json;
    auth_basic off;
    allow all;
    
    # Try to serve the static file first. 
    # If it doesn't exist, forward the request to the main application via Varnish.
    try_files $uri @app;
  }
  
  # Deeplink configuration
  location @app {
    {{varnish_proxy_pass}}
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_hide_header X-Varnish;
    proxy_redirect off;
  }


  ## make server to php 8.5  
  sudo update-alternatives --config php
  