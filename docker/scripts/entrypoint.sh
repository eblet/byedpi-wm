#!/bin/sh
set -e

echo "Starting ByeDPI Web Manager..."

# Update CA certificates
echo "Updating CA certificates..."
update-ca-certificates

# Ensure log directories exist with correct permissions
mkdir -p /var/log/supervisor /var/log/byedpi /app/logs/byedpi /run/nginx /app/data
chown -R nginx:nginx /app /var/log/byedpi
chmod 755 /app/byedpi/ciadpi*
chmod 666 /app/config.json /app/local.pac

# Ensure proper permissions on configuration files
find /app -name "*.txt" -type f -exec chmod 644 {} \;
find /app -name "*.json" -type f -exec chmod 644 {} \;
find /app -name "*.php" -type f -exec chmod 644 {} \;

# Show binary info for debugging
echo "ByeDPI binary info:"
ls -la /app/byedpi/ciadpi*
echo "Architecture: $(uname -m)"
echo "Platform: $(uname -a)"

# Show SSL certificate info for debugging
echo "SSL certificates info:"
if [ -f "/etc/ssl/certs/ca-certificates.crt" ]; then
    echo "System CA bundle found at /etc/ssl/certs/ca-certificates.crt ($(wc -l < /etc/ssl/certs/ca-certificates.crt) lines)"
else
    echo "Warning: System CA bundle not found at expected location"
fi

# Test if binary works
if [ -x "/app/byedpi/ciadpi" ]; then
    echo "Testing ByeDPI binary..."
    /app/byedpi/ciadpi --help > /dev/null 2>&1 && echo "ByeDPI binary is working" || echo "Warning: ByeDPI binary test failed"
else
    echo "Error: ByeDPI binary is not executable"
fi

# Create initial config if it doesn't exist
if [ ! -f "/app/config.json" ]; then
    echo "Creating initial config..."
    cat > /app/config.json << 'EOF'
{
    "local_ip": "0.0.0.0",
    "ciadpi_main_servers_tcp_ports": [20001, 20002, 20003, 20004, 20005, 20006, 20007, 20008],
    "ciadpi_main_servers_latest_used_strategies": ["", "", "", "", "", "", "", ""],
    "ciadpi_test_servers_tcp_ports": [20009, 20010, 20011, 20012, 20013, 20014, 20015, 20016, 20017, 20018, 20019, 20020, 20021, 20022, 20023, 20024, 20025, 20026, 20027, 20028],
    "select_links": [
        "https://www.youtube.com/watch?v=jNQXAC9IVRw",
        "https://www.google.com/search?q=test"
    ]
}
EOF
    chown nginx:nginx /app/config.json
fi

echo "Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
