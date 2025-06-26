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

## Using the Server Manager Script (`server_manager.py`)

To simplify starting and stopping the PHP activation server and managing hosts file entries, a Python 3 script `server_manager.py` is provided. Ensure `activator.php` is in the same directory as `server_manager.py`.

**Prerequisites for `server_manager.py`:**
*   Python 3 installed.
*   PHP CLI installed and in your system's PATH.

**Permissions:**
*   **Hosts file modification & running on privileged ports (like 80):** The `server_manager.py` script will need to be run with administrator/sudo privileges.
    *   **Windows:** Run your terminal (Command Prompt or PowerShell) "As Administrator", then run the script.
    *   **macOS/Linux:** Use `sudo python3 server_manager.py <command>`.

**Commands:**

### Start the Server
```bash
# On macOS/Linux (use sudo for hosts modification and port 80)
sudo python3 server_manager.py start

# On Windows (run terminal as Administrator)
python server_manager.py start
```
*   This command will:
    1.  Check if PHP is installed.
    2.  Prompt you to add `127.0.0.1 albert.apple.com` to your hosts file if it's not already present (requires admin/sudo). You can skip this with `--no-hosts`.
    3.  Start the PHP built-in server, listening on `0.0.0.0:80` by default (serves `activator.php`). Port 80 requires admin/sudo.
        *   You can specify a different port: `sudo python3 server_manager.py start --port 8080` (If using a non-80 port, `albert.apple.com` redirection alone won't be enough for iTunes; client would need to target `albert.apple.com:8080`).
    4.  The script will keep running. Press `Ctrl+C` in the terminal to stop the server.

### Stop the Server
```bash
# On macOS/Linux
sudo python3 server_manager.py stop

# On Windows (run terminal as Administrator)
python server_manager.py stop
```
*   This command will:
    1.  Stop the PHP server that was started by the `start` command (using its saved PID).
    2.  Prompt you to remove the `127.0.0.1 albert.apple.com` entry from your hosts file if it's present (requires admin/sudo). You can skip this with `--no-hosts`.

### Command Options
*   `--port <number>`: (For `start` command) Specify a custom port for the PHP server. Default is 80.
*   `--no-hosts`: (For `start` and `stop` commands) Skip the interactive hosts file modification steps. Useful if you manage your hosts file manually or are not targeting `albert.apple.com`.

**Important Note on SSL for iTunes:**
The `server_manager.py` script starts the PHP server over HTTP. For full compatibility with iTunes, which typically expects `https://albert.apple.com`, you would still need to manually:
1.  Set up a reverse proxy (like Nginx or Apache) to handle HTTPS for `albert.apple.com` and forward requests to the HTTP PHP server started by the script.
2.  Create and install a self-signed SSL certificate for `albert.apple.com` that your system trusts.
Alternatively, use a more advanced local server setup that can directly serve PHP over HTTPS.

## Manual Setup Prerequisites

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

## `activator2.0.php`: Activation Simulation with Simulated Lock Status

`activator2.0.php` extends the functionality of `activator.php` by introducing a local SQLite database (`activation_simulator.sqlite`) to store device information and a **simulated** activation lock status. This is for **educational purposes only** to demonstrate how a server might handle different device states. **It does not interact with or affect real Apple Activation Lock.**

### Key Features of `activator2.0.php`:

*   **Local Database:** Uses SQLite to keep track of devices that have attempted activation.
*   **Simulated Lock:** Each device in the database has an `is_simulated_locked` flag (0 for unlocked, 1 for locked).
*   **Conditional Response:**
    *   If a device is marked as "simulated locked" in the local database, `activator2.0.php` will return a basic HTML page indicating it's locked (with a custom message if set).
    *   If a device is "simulated unlocked" (or new to the database, defaulting to unlocked), it will generate and return a full activation record, wrapped in the iTunes-style HTML response.
*   **Device Registration:** When a device attempts activation for the first time, its details (UDID, Serial, IMEI, Product Type) are saved to the database.
*   **Reuses Activation Logic:** Utilizes the same core `ActivationGenerator` class to create activation records for "unlocked" devices.

### Database Schema (`activation_simulator.sqlite` - table `devices`)

*   `id`: INTEGER, Primary Key, Auto-increment
*   `udid`: TEXT, Unique, Not Null (Primary device identifier)
*   `serial_number`: TEXT, Unique (Secondary identifier)
*   `imei`: TEXT
*   `product_type`: TEXT
*   `is_simulated_locked`: INTEGER, Not Null, Default 0 (0 = unlocked, 1 = locked)
*   `simulated_lock_message`: TEXT (Custom message for simulated lock screen)
*   `activation_record_xml`: TEXT (Stores the last generated activation record for an unlocked device)
*   `notes`: TEXT
*   `first_seen_timestamp`: DATETIME, Default CURRENT_TIMESTAMP
*   `last_activation_attempt_timestamp`: DATETIME, Default CURRENT_TIMESTAMP

### `manage_lock.php`: Managing Simulated Lock Status

A companion script, `manage_lock.php`, provides a web interface to:
*   View all devices registered in the `activation_simulator.sqlite` database.
*   Search for specific devices by UDID or Serial Number.
*   Manually set a device's `is_simulated_locked` status to `1` (locked) or `0` (unlocked).
*   Set a custom `simulated_lock_message` when locking a device.
*   Delete device records from the simulation.

**How to use `activator2.0.php` and `manage_lock.php`:**

1.  **Setup:**
    *   Ensure `activator2.0.php`, `manage_lock.php` are in your PHP server's document root. The `ActivationGenerator` class is included within `activator2.0.php`.
    *   The PHP server must have write permissions in its directory to create and manage `activation_simulator.sqlite`.
    *   Use `server_manager.py` (recommended) or manual setup to run the PHP server, pointing `albert.apple.com` to it.
        *   When using `server_manager.py`: By default, it looks for `activator.php`. To use `activator2.0.php` as the primary activation endpoint for `server_manager.py`, you should rename `activator2.0.php` to `activator.php` in your filesystem, or modify `server_manager.py` to target `activator2.0.php`.
        *   Access `manage_lock.php` by browsing to `http://localhost/manage_lock.php` (or `http://albert.apple.com/manage_lock.php` if your server is set up that way and `manage_lock.php` is in the docroot for `albert.apple.com`).

2.  **First Activation Attempt:**
    *   When an iDevice (via iTunes or other tools) sends an activation request to your server (which should be running `activator2.0.php`, possibly renamed to `activator.php`), the script will:
        *   Parse the device details.
        *   Add the device to the `activation_simulator.sqlite` database with `is_simulated_locked = 0` (unlocked).
        *   Generate a full activation record and return it in the iTunes HTML format.
        *   You can then view this device in `manage_lock.php`.

3.  **Simulating a Locked Device:**
    *   Open `manage_lock.php` in your web browser.
    *   Find the device you want to simulate as locked.
    *   Use the form or row action to set its status to "LOCKED". You can add a custom message like "This iPhone is locked to a simulated Apple ID."
    *   Now, if the same iDevice attempts activation again via `activator2.0.php` (or `activator.php` if renamed), it will receive the HTML page indicating it's "simulated locked" with your message.

4.  **Simulating an Unlocked Device:**
    *   Use `manage_lock.php` to set the device's status back to "UNLOCKED".
    *   The next activation attempt through `activator2.0.php` (or `activator.php` if renamed) will again generate a full activation record.

**Important:**
*   This entire system is a **local simulation**. It does not affect real Apple servers or real device lock statuses.
```
