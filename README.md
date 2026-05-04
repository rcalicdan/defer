# Defer - PHP Deferred Execution Library

A framework-agnostic PHP library that provides Go-style `defer` functionality for resource management and cleanup operations. Execute callbacks at different scopes: function-level, global (shutdown), or after HTTP response termination.

## Installation

```bash
composer require rcalicdan/defer
```

**Requirements:** PHP 8.2+

## Quick Start

```php
use Rcalicdan\Defer\Defer;

function processFile($filename) {
    $file = fopen($filename, 'r');
    
    // Defer cleanup - executes when function ends
    $defer = Defer::scope();
    $defer->task(fn() => fclose($file));
    
    // Your file processing logic here
    $data = fread($file, 1024);
    
    // File automatically closed when function returns
    return $data;
}
```

## Core Concepts

The library provides three execution scopes:

1. **Function Scope** - Executes when the defer instance goes out of scope (LIFO order)
2. **Global Scope** - Executes during script shutdown (LIFO order)
3. **Terminate Scope** - Executes after HTTP response is sent (FIFO order)

## Function-Scoped Defers

Function-scoped defers execute when the `DeferInstance` object is destroyed (typically when leaving the function scope). They execute in **LIFO (Last In, First Out)** order.

### Basic Usage

```php
use Rcalicdan\Defer\Defer;

function databaseTransaction() {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->beginTransaction();
    
    $defer = Defer::scope();
    $defer->task(fn() => $pdo->rollback()); // Safety rollback
    
    // Perform operations
    $stmt = $pdo->prepare("INSERT INTO users (name) VALUES (?)");
    $stmt->execute(['John']);
    
    $pdo->commit();
    // Defer cleanup executes here (though rollback is harmless after commit)
}
```

### Method Chaining

```php
$defer = Defer::scope()
    ->task(fn() => fclose($file))
    ->task(fn() => unlink($tempFile))
    ->task(fn() => echo "Cleanup completed\n");

// Execution order: echo message, unlink file, close file (LIFO)
```

### Multiple Resources

```php
function processMultipleFiles(array $filenames) {
    $defer = Defer::scope();
    $handles = [];
    
    foreach ($filenames as $filename) {
        $handle = fopen($filename, 'r');
        $handles[] = $handle;
        
        // Each file gets its own cleanup defer
        $defer->task(fn() => fclose($handle));
    }
    
    // Process all files
    foreach ($handles as $handle) {
        // ... process file
    }
    
    // All files automatically closed when function ends (LIFO order)
}
```

## Global Defers

Global defers execute during **normal script shutdown** in **LIFO (Last In, First Out)** order. They are guaranteed to run when the script exits naturally or encounters a fatal error.

```php
use Rcalicdan\Defer\Defer;

Defer::global(function() {
    echo "First registered\n";
});

Defer::global(function() {
    echo "Second registered\n";  
});

Defer::global(function() {
    echo "Third registered\n";
});

// Output on shutdown (LIFO order):
// Third registered
// Second registered  
// First registered
```

### Practical Example

```php
Defer::global(fn() => echo "1. Final cleanup completed\n");
Defer::global(fn() => close_database_connections());
Defer::global(fn() => cleanup_temp_files());
Defer::global(fn() => echo "4. Starting cleanup sequence...\n");

$app = new Application();

Defer::global(fn() => $app->saveState());
```

### Signal Handling (Opt-In)

By default, global defers only run on **normal script shutdown**. If you need defers to also run when the process is interrupted (e.g. `Ctrl+C`, `SIGTERM`, `SIGHUP`), you must explicitly opt in by calling `Defer::enableSignals()` early in your script.

This is intentionally disabled by default — registering signal handlers can have unexpected side effects in web contexts, test runners, and scripts that manage their own signals.

```php
// Opt in once, early in your entry point
Defer::enableSignals();

Defer::global(function() {
    file_put_contents('/tmp/shutdown.log', 'Clean shutdown: ' . date('Y-m-d H:i:s'));
});

// Your long-running process
while (true) {
    // ... do work
    sleep(1);
}

// Without enableSignals(): cleanup runs on normal exit only
// With enableSignals(): cleanup also runs on Ctrl+C, SIGTERM, SIGHUP, etc.
```

**Signal support by platform (when opted in):**

- **Windows** — `sapi_windows_set_ctrl_handler()` (Ctrl+C, Ctrl+Break, window close)
- **Unix/Linux with pcntl** — `SIGTERM`, `SIGINT`, `SIGHUP`
- **Unix/Linux without pcntl** — process monitoring, STDIN monitoring, error handler fallbacks
- **All platforms** — `register_shutdown_function()` as the guaranteed baseline

> **Note:** Signal handling is only meaningful in CLI. Calling `Defer::enableSignals()` in a web context is a safe no-op.

## Terminate Defers

Terminate defers execute after the HTTP response is sent to the client in **FIFO (First In, First Out)** order, allowing for background processing without impacting response time.

**Note:** Terminate defers work best in **FastCGI environments** (PHP-FPM, FastCGI) where `fastcgi_finish_request()` is available. Other environments use fallback methods but may not guarantee true post-response execution.

### Basic Usage

```php
use Rcalicdan\Defer\Defer;

function handleRequest($request) {
    $response = processRequest($request);
    
    // Background tasks execute in FIFO order after response is sent
    Defer::terminate(function() use ($request) {
        logAnalytics($request->getUri(), $request->getUserAgent());
    });
    
    Defer::terminate(function() use ($request) {
        sendWelcomeEmail($request->get('email'));
    });
    
    return $response;
}
```

### Error Handling

By default, terminate defers skip execution on 4xx/5xx HTTP status codes. Use the `$always` parameter to force execution:

```php
// Only runs on successful responses (2xx, 3xx)
Defer::terminate(fn() => incrementSuccessCounter());

// Always runs, regardless of status code
Defer::terminate(function() {
    logRequestCompletion();
}, always: true);
```

### Environment Support

- **FastCGI/FPM** ✅ — Uses `fastcgi_finish_request()` for true post-response execution
- **CLI** — Executes after main script completion
- **Development Server** — Flushes output buffers before execution
- **Other SAPIs** — Fallback with output buffer handling

## Advanced Usage

### Manual Execution (Testing)

```php
// Function-scoped - manual execution in LIFO order
$defer = Defer::scope();
$defer->task(fn() => echo "Second\n");
$defer->task(fn() => echo "First\n");
$defer->executeAll();

// Global - manual execution in LIFO order
Defer::global(fn() => echo "Global cleanup\n");
Defer::getHandler()->executeAll();

// Terminate - manual execution in FIFO order
Defer::terminate(fn() => echo "First task\n");
Defer::terminate(fn() => echo "Second task\n");
Defer::getHandler()->executeTerminate();
```

### Monitoring and Debugging

```php
// Check pending defer count
$defer = Defer::scope();
$defer->task(fn() => cleanup1());
$defer->task(fn() => cleanup2());
echo $defer->count(); // 2

// Check whether signal handling is active
var_dump(Defer::signalsEnabled()); // bool(false) by default

// Inspect signal handling capabilities
$info = Defer::getHandler()->getSignalHandlingInfo();
print_r($info);

// Run the built-in capability test (outputs platform/method details)
Defer::getHandler()->testSignalHandling();
```

### Checking FastCGI Availability

```php
$info = Defer::getHandler()->getHandler()->getEnvironmentInfo();

if ($info['fastcgi'] && $info['fastcgi_finish_request']) {
    echo "✅ Optimal terminate defer support available\n";
} else {
    echo "⚠️ Using fallback terminate handling\n";
}
```

## Error Handling

All defer types include robust error handling. Exceptions in callbacks are logged but do not prevent remaining callbacks from executing:

```php
$defer = Defer::scope()
    ->task(function() {
        throw new Exception("This won't stop other defers");
    })
    ->task(function() {
        echo "This will still execute\n";
    });

// Exception is logged via error_log(), execution continues in LIFO order
```

## Performance Considerations

- **Function Scope**: Limited to 50 defers per instance (oldest dropped when exceeded)
- **Global Scope**: Limited to 100 defers total (oldest dropped when exceeded)
- **Terminate Scope**: Limited to 50 defers (oldest dropped when exceeded)
- Function and Global defers execute in LIFO order
- Terminate defers execute in FIFO order
- Minimal overhead for registration and cleanup

## Real-World Examples

### Database Transaction with Cleanup

```php
function transferFunds($fromAccount, $toAccount, $amount) {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->beginTransaction();
    
    $defer = Defer::scope()
        ->task(fn() => auditLog("Transaction attempt completed")) // Runs first (LIFO)
        ->task(fn() => $pdo->rollback());                        // Runs second - safety net
    
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$amount, $fromAccount]);
    
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$amount, $toAccount]);
    
    $pdo->commit();
    // LIFO: audit log, then rollback (harmless after commit)
}
```

### File Processing with Temporary Cleanup

```php
function processUploadedImage($uploadedFile) {
    $tempPath = '/tmp/' . uniqid() . '.tmp';
    move_uploaded_file($uploadedFile['tmp_name'], $tempPath);
    
    $defer = Defer::scope()
        ->task(fn() => echo "Processing completed\n") // Runs first (LIFO)
        ->task(fn() => unlink($tempPath));             // Runs second - cleanup
    
    $image = imagecreatefromjpeg($tempPath);
    $resized = imagescale($image, 800, 600);
    
    $finalPath = '/uploads/' . $uploadedFile['name'];
    imagejpeg($resized, $finalPath);
    
    imagedestroy($image);
    imagedestroy($resized);
    
    return $finalPath;
}
```

### Background Processing with Terminate

```php
function processOrder($orderData) {
    $order = createOrder($orderData);
    
    // Background tasks execute in FIFO order after response is sent
    Defer::terminate(function() use ($order) {
        updateInventory($order);        // Runs first
    });
    
    Defer::terminate(function() use ($order) {
        sendConfirmationEmail($order);  // Runs second
    });
    
    Defer::terminate(function() use ($order) {
        logOrderCompletion($order);     // Runs third
    });
    
    return ['success' => true, 'order_id' => $order->id];
}
```

### Long-Running CLI Process with Graceful Shutdown

```php
// Opt in to signal handling for graceful interruption
Defer::enableSignals();

// Register cleanup in LIFO order
Defer::global(fn() => echo "Shutdown complete\n");
Defer::global(function() {
    file_put_contents('/var/log/worker.log', "Worker stopped: " . date('c') . "\n", FILE_APPEND);
});
Defer::global(fn() => echo "Starting shutdown sequence...\n");

while (true) {
    $job = getNextJob();
    if (!$job) {
        sleep(1);
        continue;
    }
    
    processJob($job);
}

// LIFO cleanup runs on normal exit AND on Ctrl+C / SIGTERM (because enableSignals() was called)
```

## Execution Order Summary

| Scope | Order | Triggered by |
|---|---|---|
| Function | LIFO | Instance going out of scope |
| Global | LIFO | Script shutdown (+ signals if opted in) |
| Terminate | FIFO | After HTTP response is sent |

## Limitations

1. **Signal handling** is opt-in via `Defer::enableSignals()` — global defers only cover normal shutdown by default
2. **Terminate defers** work optimally in FastCGI environments; other environments use fallback methods
3. **Exceptions** in defer callbacks are logged but do not propagate
4. **Defer stacks** have size limits to prevent memory leaks (see Performance Considerations)
5. **Execution order** differs by scope — plan your cleanup registration accordingly

## License

MIT License - see [LICENSE](LICENSE) file for details.