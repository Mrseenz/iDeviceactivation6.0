#!/usr/bin/env python3
# server_manager.py

import argparse
import subprocess
import platform
import os
import sys
import time
import signal

# Configuration
HOSTS_ENTRY = "127.0.0.1 albert.apple.com"
PHP_SERVER_PID_FILE = ".php_server.pid"
DEFAULT_PORT = 80
DOC_ROOT = "." # Assuming activator.php is in the same directory as this script

# --- Helper Functions ---

def check_php_installed():
    """Checks if PHP CLI is installed and accessible."""
    try:
        subprocess.run(["php", "--version"], capture_output=True, check=True, text=True)
        print("✅ PHP is installed.")
        return True
    except (subprocess.CalledProcessError, FileNotFoundError):
        print("❌ PHP is not installed or not in PATH. Please install PHP and ensure it's in your system PATH.")
        return False

def get_hosts_file_path():
    """Returns the OS-specific path to the hosts file."""
    system = platform.system().lower()
    if system == "windows":
        return os.path.join(os.environ.get("SystemRoot", "C:\\Windows"), "System32\\drivers\\etc\\hosts")
    elif system in ["linux", "darwin"]: # darwin is macOS
        return "/etc/hosts"
    else:
        print(f"Unsupported OS: {platform.system()}")
        return None

def is_entry_in_hosts(entry_to_check=HOSTS_ENTRY):
    """Checks if the specified entry is in the hosts file."""
    hosts_path = get_hosts_file_path()
    if not hosts_path:
        return False
    try:
        with open(hosts_path, "r") as f:
            for line in f:
                if entry_to_check in line and not line.strip().startswith("#"):
                    return True
    except FileNotFoundError:
        print(f"🔍 Hosts file not found at {hosts_path} (This is unexpected).")
        return False
    except Exception as e:
        print(f"⚠️ Error reading hosts file: {e}")
        return False # Assume not present if error
    return False

def _run_privileged_command(command_list):
    """
    Attempts to run a command that typically requires elevated privileges.
    For Linux/macOS, prepends 'sudo'. For Windows, it just runs it and
    relies on the script itself being run as Administrator.
    Returns True on success (exit code 0), False otherwise.
    """
    system = platform.system().lower()
    if system in ["linux", "darwin"]:
        full_command = ["sudo"] + command_list
    elif system == "windows":
        full_command = command_list # Assumes script is run as Admin
    else:
        print(f"Unsupported OS for privileged command: {platform.system()}")
        return False

    try:
        print(f"ℹ️  Attempting to run: {' '.join(full_command)}")
        print( "    This may ask for your administrator/sudo password to modify the hosts file.")
        # Using shell=True for Windows to correctly interpret built-in commands if needed,
        # and to handle sudo correctly on Unix-like systems.
        # For simple commands like 'echo >> hosts', it's often easier.
        # However, direct file I/O is safer for hosts file modification.
        # This function is a placeholder for a more robust solution if direct I/O fails.
        # For now, we will use direct file I/O in add/remove functions.
        # This function can be used for other privileged tasks if needed.
        # For hosts, we'll use direct write with a sudo wrapper for the python script itself.
        # This function is therefore simplified for now or might be removed if not used.

        # Re-evaluating: direct file I/O is better. This function might not be needed
        # if we guide user to run the whole script with sudo/admin.
        # Let's assume the whole script is run with necessary privileges for now.
        # subprocess.run(full_command, check=True)
        # For modifying hosts file, it's better to read content, modify, and write back.
        # This function will be simplified as direct write is preferred.
        pass # Placeholder, direct I/O to be implemented in add/remove
        return True # Assume success for now
    except subprocess.CalledProcessError as e:
        print(f"❌ Error executing privileged command: {e}")
        return False
    except FileNotFoundError:
        if system in ["linux", "darwin"] and command_list[0] != "sudo": # if sudo itself is not found
             print("❌ sudo command not found. Please ensure it's installed and in PATH for Linux/macOS.")
        else:
            print(f"❌ Command not found: {command_list[0]}")
        return False


def add_to_hosts(entry_to_add=HOSTS_ENTRY):
    """Adds the entry to the hosts file. Assumes script is run with sufficient privileges."""
    hosts_path = get_hosts_file_path()
    if not hosts_path:
        return False
    if is_entry_in_hosts(entry_to_add):
        print(f"✅ Hosts entry '{entry_to_add}' already exists.")
        return True
    try:
        with open(hosts_path, "a") as f:
            f.write(f"\n{entry_to_add}\n")
        print(f"✅ Added '{entry_to_add}' to hosts file ({hosts_path}).")
        if platform.system().lower() in ["linux", "darwin"]:
            print("ℹ️  Note: DNS cache flush might be needed on some systems (e.g., `sudo dscacheutil -flushcache` on macOS or restart nscd/systemd-resolved on Linux).")
        elif platform.system().lower() == "windows":
            print("ℹ️  Attempting to flush DNS cache on Windows...")
            try:
                subprocess.run(["ipconfig", "/flushdns"], capture_output=True, check=True)
                print("✅ Windows DNS cache flushed.")
            except Exception as e:
                print(f"⚠️ Could not flush Windows DNS cache automatically: {e}. A reboot or manual flush might be needed.")
        return True
    except Exception as e:
        print(f"❌ Failed to add entry to hosts file: {e}")
        print(f"   Ensure you are running this script with administrator/sudo privileges.")
        return False

def remove_from_hosts(entry_to_remove=HOSTS_ENTRY):
    """Removes the entry from the hosts file. Assumes script is run with sufficient privileges."""
    hosts_path = get_hosts_file_path()
    if not hosts_path:
        return False
    if not is_entry_in_hosts(entry_to_remove):
        print(f"✅ Hosts entry '{entry_to_remove}' not found, no removal needed.")
        return True
    try:
        with open(hosts_path, "r") as f:
            lines = f.readlines()
        with open(hosts_path, "w") as f:
            entry_found_and_removed = False
            for line in lines:
                if entry_to_remove in line and not line.strip().startswith("#"):
                    entry_found_and_removed = True
                    # Skip writing this line to remove it
                else:
                    f.write(line)
        if entry_found_and_removed:
            print(f"✅ Removed '{entry_to_remove}' from hosts file ({hosts_path}).")
        else:
            # This case should be caught by is_entry_in_hosts, but as a safeguard:
            print(f"ℹ️  Entry '{entry_to_remove}' was not actively present to remove (e.g. commented out or formatting mismatch).")

        if platform.system().lower() in ["linux", "darwin"]:
             print("ℹ️  Note: DNS cache flush might be needed on some systems.")
        elif platform.system().lower() == "windows":
            print("ℹ️  Attempting to flush DNS cache on Windows...")
            try:
                subprocess.run(["ipconfig", "/flushdns"], capture_output=True, check=True)
                print("✅ Windows DNS cache flushed.")
            except Exception as e:
                print(f"⚠️ Could not flush Windows DNS cache automatically: {e}. A reboot or manual flush might be needed.")
        return True
    except Exception as e:
        print(f"❌ Failed to remove entry from hosts file: {e}")
        print(f"   Ensure you are running this script with administrator/sudo privileges.")
        return False


# --- Main Server Logic ---

def start_php_server(port=DEFAULT_PORT, doc_root=DOC_ROOT):
    """Starts the PHP built-in web server as a subprocess."""
    if not os.path.exists("activator.php"):
        print(f"❌ activator.php not found in the current directory ({os.getcwd()}).")
        print(f"   Please ensure activator.php is in the same directory as this script, or specify the correct document root.")
        return None

    # Check if port is privileged
    is_privileged_port = (port < 1024)
    needs_admin_privileges = False
    current_user_has_admin = False

    system = platform.system().lower()
    if system in ["linux", "darwin"]:
        # On Unix, os.geteuid() == 0 means root
        current_user_has_admin = (os.geteuid() == 0)
    elif system == "windows":
        try:
            # On Windows, check if the user is an administrator.
            import ctypes
            current_user_has_admin = ctypes.windll.shell32.IsUserAnAdmin() != 0
        except Exception:
            # If ctypes fails (e.g. not available, though standard), assume not admin.
            current_user_has_admin = False
            print("⚠️ Could not determine admin status on Windows via ctypes.")


    if is_privileged_port and not current_user_has_admin:
        print(f"⚠️ Warning: Port {port} is a privileged port.")
        if system == "windows":
            print(f"   You might need to run this script as an Administrator to use port {port}.")
        else: # Linux/macOS
            print(f"   You might need to use 'sudo python3 server_manager.py start --port {port}' or run as root.")
        # We can still attempt to start, PHP might fail with a permission error

    php_command = ["php", f"-S", f"0.0.0.0:{port}", "-t", doc_root]
    print(f"🚀 Starting PHP server: {' '.join(php_command)}")
    print(f"   Serving from: {os.path.abspath(doc_root)}")
    print(f"   PHP server output will be shown below. Press Ctrl+C here to try to stop it.")

    try:
        # Start the server in a way that it can be managed (e.g., not blocking the Python script entirely if possible,
        # or by detaching and storing PID)
        # For simplicity with PHP's built-in server, it's often run and monitored directly.
        # Popen allows us to manage it as a subprocess.
        process = subprocess.Popen(php_command, stdout=sys.stdout, stderr=sys.stderr)

        # Save PID to stop it later
        try:
            with open(PHP_SERVER_PID_FILE, "w") as f:
                f.write(str(process.pid))
            print(f"✅ PHP server started with PID {process.pid} (PID saved to {PHP_SERVER_PID_FILE}).")
        except IOError as e:
            print(f"⚠️ Could not write PID file {PHP_SERVER_PID_FILE}: {e}")
            print(f"   You may need to stop the PHP server manually if this script fails to.")

        return process
    except FileNotFoundError:
        print("❌ PHP command not found. Ensure PHP is installed and in your PATH.")
        # check_php_installed() would have caught this earlier, but as a safeguard.
        return None
    except Exception as e:
        print(f"❌ Failed to start PHP server: {e}")
        return None

def stop_php_server():
    """Stops the PHP server using the PID file."""
    if not os.path.exists(PHP_SERVER_PID_FILE):
        print("ℹ️  PHP server PID file not found. Server may not be running or was not started by this script.")
        # As a fallback, try to kill any php process listening on the default port (more aggressive)
        # This is OS-dependent and riskier. For now, rely on PID.
        # print("   Attempting to find and kill PHP server by port (Not yet implemented).")
        return False

    try:
        with open(PHP_SERVER_PID_FILE, "r") as f:
            pid = int(f.read().strip())
    except (IOError, ValueError) as e:
        print(f"⚠️ Error reading PID file {PHP_SERVER_PID_FILE}: {e}. Cannot stop server by PID.")
        return False

    print(f"🛑 Stopping PHP server with PID {pid}...")
    try:
        # Send SIGTERM first for graceful shutdown
        os.kill(pid, signal.SIGTERM)
        print(f"   Sent SIGTERM to PID {pid}. Waiting a moment...")
        time.sleep(2) # Give it a couple of seconds to shut down

        # Check if process still exists
        # os.kill(pid, 0) will raise an OSError if the process does not exist.
        try:
            os.kill(pid, 0) # Check if process is still alive
            print(f"   Server with PID {pid} did not terminate with SIGTERM. Sending SIGKILL...")
            os.kill(pid, signal.SIGKILL) # Force kill
            print(f"   Sent SIGKILL to PID {pid}.")
        except OSError:
            print(f"✅ Server with PID {pid} terminated successfully.")

        success = True
    except ProcessLookupError: # PID not found
        print(f"✅ Process with PID {pid} not found. Assumed already stopped.")
        success = True
    except Exception as e:
        print(f"❌ Error stopping PHP server (PID: {pid}): {e}")
        print(f"   You may need to stop it manually (e.g., using Task Manager or 'kill {pid}').")
        success = False

    if success:
        try:
            os.remove(PHP_SERVER_PID_FILE)
            print(f"✅ Removed PID file {PHP_SERVER_PID_FILE}.")
        except OSError as e:
            print(f"⚠️ Could not remove PID file {PHP_SERVER_PID_FILE}: {e}")
    return success

# (CLI logic will use these functions)

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Manage the PHP iDevice Activation Server.")
    subparsers = parser.add_subparsers(dest="command", help="Available commands")

    # Start command
    start_parser = subparsers.add_parser("start", help="Start the PHP server and configure hosts file.")
    start_parser.add_argument("--port", type=int, default=DEFAULT_PORT, help=f"Port for the PHP server (default: {DEFAULT_PORT})")
    start_parser.add_argument("--no-hosts", action="store_true", help="Skip hosts file modification.")

    # Stop command
    stop_parser = subparsers.add_parser("stop", help="Stop the PHP server and revert hosts file.")
    stop_parser.add_argument("--no-hosts", action="store_true", help="Skip hosts file modification (revert).")

    # Status command (optional, can be added later)
    # status_parser = subparsers.add_parser("status", help="Check server and hosts file status.")

    args = parser.parse_args()

    # --- Command Handling ---
    php_process = None # To hold the server process if started

    def manage_hosts_add_interactive(skip_hosts_mod):
        if skip_hosts_mod:
            print("ℹ️  Skipping hosts file modification as per --no-hosts.")
            return
        if not is_entry_in_hosts():
            print(f"❓ The hosts file entry '{HOSTS_ENTRY}' is not present.")
            choice = input("   Do you want to add it now? This usually requires admin/sudo privileges. (y/n): ").lower()
            if choice == 'y':
                if not add_to_hosts():
                    print("⚠️ Failed to add hosts entry. Manual addition might be required, or run script with sufficient privileges.")
                    sys.exit(1) # Exit if hosts modification was requested but failed.
            else:
                print("ℹ️  Skipping hosts file addition. The server might not be reachable via 'albert.apple.com'.")
        else:
            print(f"✅ Hosts entry '{HOSTS_ENTRY}' is already present.")

    def manage_hosts_remove_interactive(skip_hosts_mod):
        if skip_hosts_mod:
            print("ℹ️  Skipping hosts file removal as per --no-hosts.")
            return
        if is_entry_in_hosts(): # Check again in case it was removed manually
            print(f"❓ The hosts file entry '{HOSTS_ENTRY}' is present.")
            choice = input("   Do you want to remove it now? This usually requires admin/sudo privileges. (y/n): ").lower()
            if choice == 'y':
                if not remove_from_hosts():
                    print("⚠️ Failed to remove hosts entry. Manual removal might be required, or run script with sufficient privileges.")
            else:
                print("ℹ️  Skipping hosts file removal. Remember to remove it manually later if needed.")
        else:
            print(f"✅ Hosts entry '{HOSTS_ENTRY}' is not present, no removal needed.")


    if args.command == "start":
        print("--- Starting PHP iDevice Activation Server ---")
        if not check_php_installed():
            sys.exit(1)

        manage_hosts_add_interactive(args.no_hosts)

        php_process = start_php_server(args.port, DOC_ROOT)

        if php_process:
            print(f"🎉 Server started successfully on port {args.port}.")
            print( "   If hosts file is configured, iTunes/device should now connect to this server via http://albert.apple.com" + (f":{args.port}" if args.port != 80 else ""))
            print( "   For full iTunes compatibility, ensure your system trusts a self-signed SSL cert for albert.apple.com on this port if iTunes uses HTTPS.")
            print( "   Press Ctrl+C in this window to stop the server.")
            try:
                # Keep the script alive while the PHP server runs,
                # and listen for Ctrl+C to stop it.
                while php_process.poll() is None: # While process is running
                    time.sleep(0.5)
                print("ℹ️  PHP server process seems to have terminated on its own.")
            except KeyboardInterrupt:
                print("\n⌨️ Ctrl+C detected. Stopping server...")
            finally:
                # This block will run if loop finishes or on KeyboardInterrupt from within this script
                if php_process and php_process.poll() is None: # If still running (e.g. Ctrl+C outside of Popen's direct handling)
                    stop_php_server() # Use our PID based stop
                manage_hosts_remove_interactive(args.no_hosts) # Offer to remove hosts on exit
                print("--- Server shutdown complete ---")
        else:
            print("❌ Server failed to start.")
            # Attempt to cleanup hosts if it was added by this run and start failed
            if not args.no_hosts and is_entry_in_hosts(HOSTS_ENTRY): # Check if it was added
                 print("Attempting to revert hosts file change due to server start failure...")
                 remove_from_hosts(HOSTS_ENTRY) # Non-interactive removal on failure
            sys.exit(1)

    elif args.command == "stop":
        print("--- Stopping PHP iDevice Activation Server ---")
        if not stop_php_server():
            print("⚠️  Server may not have stopped cleanly. Check manually.")
        else:
            print("✅ Server stopped successfully or was not running.")
        manage_hosts_remove_interactive(args.no_hosts)
        print("--- Server shutdown process complete ---")

    else:
        parser.print_help()
