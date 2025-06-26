# iDevice Activation Server (PHP)

A PHP-based server script (`activator.php`) that emulates Apple's iDevice activation process. This can be used to receive activation requests from an iDevice (e.g., via iTunes or other tools) and generate an activation record.

**FOR EDUCATIONAL AND RESEARCH PURPOSES ONLY. Modifying your device's activation process may have unintended consequences. Proceed with caution and at your own risk.**

## Current Functionality
The `activator.php` script can:
- Listen for iDevice activation POST requests.
- Support requests in `multipart/form-data` format (as sent by iTunes).
- Support requests with raw XML plist data in the POST body.
- Parse device information from the request.
- Dynamically generate cryptographic elements (certificates, signed tokens) needed for an activation record.
- Return a complete activation record XML plist.

## Prerequisites

1.  **PHP Environment:** You need a local web server with PHP installed (e.g., XAMPP, MAMP, or using PHP's built-in server: `php -S localhost:8000`). Ensure the `openssl` PHP extension is enabled in your `php.ini`.
2.  **Hosts File Modification:** To redirect activation requests from your iDevice/iTunes to this local server, you'll need to modify your computer's `hosts` file. Add an entry like:
    ```
    127.0.0.1 albert.apple.com
    ```
    (Or the local IP of your server if running on a different machine on your network, e.g., `192.168.1.100 albert.apple.com`).
    *   **Windows:** `C:\Windows\System32\drivers\etc\hosts`
    *   **macOS/Linux:** `/etc/hosts`
    Remember to remove this entry after you're done to restore normal Apple services. Administrative privileges are usually required to edit this file.
3.  **SSL Certificate (Often Required by iTunes):** While this script itself doesn't enforce HTTPS, iTunes and modern iOS devices will attempt to connect to `https://albert.apple.com`. For successful interception from iTunes, you will likely need to:
    *   Configure your local server (e.g., Apache, Nginx) to serve `activator.php` over HTTPS for the `albert.apple.com` domain.
    *   Create a self-signed SSL certificate for `albert.apple.com`.
    *   Make your computer and potentially the iDevice trust this self-signed certificate.
    This is an advanced step and varies significantly by OS and server software. Simpler command-line tools might work over HTTP if you point them directly to your server's local IP and port.

## How to Use

### Method 1: Activation via iTunes (using `multipart/form-data`)

This method attempts to use iTunes to send the activation request to your local server.

1.  **Set up Prerequisites:**
    *   Ensure your PHP server is running and configured to handle requests for `albert.apple.com` (potentially over HTTPS, see prerequisite #3).
    *   Modify your `hosts` file to redirect `albert.apple.com` to your server's IP address.
2.  **Place `activator.php`:** Make `activator.php` the script that handles requests for `albert.apple.com` (e.g., as an `index.php` in the document root for `albert.apple.com`, or via URL rewriting).
3.  **Connect iDevice:** Connect your iDevice to your computer and open iTunes (or Finder on newer macOS).
4.  **Attempt Activation:** Initiate the device activation process in iTunes. If the `hosts` file and HTTPS setup (if needed) are correct, iTunes should send the activation request to your local `activator.php` script.
5.  **Server Response:** `activator.php` will process the `multipart/form-data` request, parse the nested XML and Base64 encoded plist, generate an activation record, and send it back to iTunes.

### Method 2: Manual Activation via Raw XML POST (e.g., using `curl`)

This method is useful if you have already captured the final, decoded activation request plist from an iDevice (the one that is Base64-encoded inside the `activation-info` from iTunes).

1.  **Set up Prerequisites:** Ensure your PHP server is running. `hosts` file modification might not be needed if you target your server's IP directly.
2.  **Get Activation Request Plist:** You need the actual XML activation request plist (a string starting with `<?xml version="1.0" ...`).
3.  **Send POST Request:** Use a tool like `curl` to send a POST request.
    *   **URL:** `http://<your_server_address_and_port>/activator.php` (e.g., `http://localhost:8000/activator.php`)
    *   **Method:** `POST`
    *   **Headers:** `Content-Type: application/xml` (or `text/xml`)
    *   **Body:** The raw XML activation request plist content.

    **Example using `curl`:**
    ```bash
    curl -X POST \
         -H "Content-Type: application/xml" \
         --data-binary "@path/to/your/decoded_activation_request.plist" \
         http://localhost:8000/activator.php
    ```
    (Replace `path/to/your/decoded_activation_request.plist` with the actual file path to the decoded XML plist).
4.  **Server Response:** The script will process the raw XML plist, generate an activation record, and send it back as an XML response.

## Troubleshooting

*   **Check Server Logs:** Your web server's access and error logs, as well as PHP's error log (`error_log` directive in `php.ini`), are crucial for diagnosing issues.
*   **Permissions:** Ensure `activator.php` is readable and executable by the web server user.
*   **PHP `openssl` Extension:** Verify it's enabled in `php.ini`.
*   **`php://input` Access:** Ensure `allow_url_fopen` is On in `php.ini` for `file_get_contents('php://input')` to work (though often On by default). `post_max_size` and `upload_max_filesize` might also be relevant for large POST requests.
*   **Content-Type Mismatches:** The script behaves differently based on the `Content-Type` header. Ensure it matches your request type.
*   **Firewall:** Check that your OS or network firewall isn't blocking connections to your server.
*   **HTTPS/SSL Issues (for iTunes):** This is the most common and complex issue. Ensure your certificate is valid for `albert.apple.com`, trusted by your system, and your web server is correctly configured for SSL for that domain. Tools like Wireshark can help inspect TLS handshake failures.
*   **Hosts File:** Double-check for typos. Flush your DNS cache after changes (`ipconfig /flushdns` on Windows, or restart networking services/reboot on macOS/Linux).
```
