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

Global defers execute during script shutdown in **LIFO (Last In, First Out)** order, regardless of how the script terminates. They're perfect for application-level cleanup and work across all platforms and environments.

```php
use Rcalicdan\Defer\Defer;

// Register cleanup that runs at script end
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
// Set up application cleanup (LIFO execution order)
Defer::global(fn() => echo "1. Final cleanup completed\n");
Defer::global(fn() => close_database_connections());
Defer::global(fn() => cleanup_temp_files());
Defer::global(fn() => echo "4. Starting cleanup sequence...\n");

// Set up application
$app = new Application();

// This will execute even if the script exits unexpectedly
Defer::global(fn() => $app->saveState());
```

### Cross-Platform Signal Handling

Global defers work reliably across all platforms and environments with automatic signal detection:

```php
// This cleanup runs on ALL platforms and termination scenarios
Defer::global(function() {
    file_put_contents('/tmp/shutdown.log', 'Clean shutdown: ' . date('Y-m-d H:i:s'));
});

// Your long-running process
while (true) {
    // ... do work
    sleep(1);
}

// Cleanup runs on:
// - Windows: Ctrl+C, Ctrl+Break, window close
// - Unix/Linux: SIGTERM, SIGINT, SIGHUP  
// - All platforms: Normal script termination, fatal errors
```

## Terminate Defers

Terminate defers execute after the HTTP response is sent to the client in **FIFO (First In, First Out)** order, allowing for background processing without impacting response time.

**Note:** Terminate defers work best in **FastCGI environments** (PHP-FPM, FastCGI) where `fastcgi_finish_request()` is available. This function properly separates response sending from background task execution. Other environments use fallback methods but may not guarantee true post-response execution.

### Basic Usage

```php
use Rcalicdan\Defer\Defer;

// In your controller/handler (works best with PHP-FPM/FastCGI)
function handleRequest($request) {
    // Process request and prepare response
    $response = processRequest($request);
    
    // Register background tasks (FIFO execution)
    Defer::terminate(function() use ($request) {
        logAnalytics($request->getUri(), $request->getUserAgent());
    });
    
    // Send email notification  
    Defer::terminate(function() use ($request) {
        sendWelcomeEmail($request->get('email'));
    });
    
    Defer::terminate(fn() => echo "Final task\n");
    
    return $response;
    // Response sent to client, then terminate defers execute in FIFO order:
    // 1. Log analytics
    // 2. Send email
    // 3. Echo message
}
```

### Environment Requirements

For optimal terminate defer functionality:

**✅ Recommended (True post-response execution):**
- PHP-FPM (FastCGI Process Manager)
- FastCGI with `fastcgi_finish_request()` available

**⚠️ Limited (Fallback behavior):**
- CLI (executes after main script)
- Development server (flushes output buffers first)
- Other SAPIs (uses output buffer flushing)

### Checking FastCGI Availability

```php
// Check if your environment supports optimal terminate functionality
$info = Defer::getHandler()->getHandler()->getEnvironmentInfo();

if ($info['fastcgi'] && $info['fastcgi_finish_request']) {
    echo "✅ Optimal terminate defer support available\n";
} else {
    echo "⚠️ Using fallback terminate handling\n";
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

Terminate defers work across different PHP environments with varying effectiveness:

- **FastCGI/FPM** ✅: Uses `fastcgi_finish_request()` for true post-response execution
- **CLI**: Executes after main script completion
- **Development Server**: Flushes output buffers before execution
- **Other SAPIs**: Fallback with output buffer handling

## Advanced Usage

### Manual Execution (Testing)

```php
// Function-scoped - for unit testing (LIFO)
$defer = Defer::scope();
$defer->task(fn() => echo "Second\n");
$defer->task(fn() => echo "First\n");
$defer->executeAll(); // Manual execution in LIFO order

// Global - for testing (LIFO)
Defer::global(fn() => echo "Global cleanup\n");
Defer::getHandler()->executeAll();

// Terminate - for testing (FIFO)
Defer::terminate(fn() => echo "First task\n");
Defer::terminate(fn() => echo "Second task\n");
Defer::getHandler()->executeTerminate();
```

### Monitoring and Debugging

```php
// Check defer counts
$defer = Defer::scope();
$defer->task(fn() => cleanup1());
$defer->task(fn() => cleanup2());
echo $defer->count(); // Output: 2

// Signal handling capabilities
$info = Defer::getHandler()->getSignalHandlingInfo();
print_r($info);
/*
Array (
    [platform] => Linux
    [sapi] => cli
    [methods] => Array (
        [0] => Unix pcntl signals
        [1] => Unix process monitoring (posix)
        [2] => STDIN monitoring
        [3] => Generic fallback (shutdown function)
    )
    [capabilities] => Array (
        [pcntl_signals] => 1
        [posix_monitoring] => 1
        [stdin_monitoring] => 1
        [shutdown_function] => 1
    )
)
*/

// Test signal handling
Defer::getHandler()->testSignalHandling();
```

### Environment Information

```php
// For terminate defers - check FastCGI capabilities
$info = Defer::getHandler()->getHandler()->getEnvironmentInfo();
print_r($info);
/*
Array (
    [sapi] => fpm-fcgi
    [fastcgi] => 1                    // FastCGI environment detected
    [fastcgi_finish_request] => 1     // Optimal function available
    [output_buffering] => 0
    [current_response_code] => 200
)
*/
```

## Error Handling

All defer types include robust error handling:

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

- **Function Scope**: Limited to 50 defers per instance (FIFO cleanup of oldest)
- **Global Scope**: Limited to 100 defers total (FIFO cleanup of oldest)  
- **Terminate Scope**: Limited to 50 defers (FIFO cleanup of oldest)
- Function and Global defers execute in LIFO order (stack-like behavior)
- Terminate defers execute in FIFO order (queue-like behavior)
- Minimal overhead for registration and cleanup
- **FastCGI environments provide the most efficient terminate defer execution**

## Real-World Examples

### Database Transaction with Cleanup

```php
function transferFunds($fromAccount, $toAccount, $amount) {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->beginTransaction();
    
    $defer = Defer::scope()
        ->task(fn() => auditLog("Transaction attempt completed")) // Executes first (LIFO)
        ->task(fn() => $pdo->rollback()); // Executes second - safety net
    
    // Debit from account
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
    $stmt->execute([$amount, $fromAccount]);
    
    // Credit to account  
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
        ->task(fn() => echo "Processing completed\n") // Executes first (LIFO)
        ->task(fn() => unlink($tempPath)); // Executes second - cleanup temp file
    
    // Process image
    $image = imagecreatefromjpeg($tempPath);
    $resized = imagescale($image, 800, 600);
    
    $finalPath = '/uploads/' . $uploadedFile['name'];
    imagejpeg($resized, $finalPath);
    
    imagedestroy($image);
    imagedestroy($resized);
    
    return $finalPath;
    // LIFO: message, then temp file cleanup
}
```

### Background Processing with Terminate (FastCGI Recommended)

```php
// Works best with PHP-FPM/FastCGI for true post-response execution
function processOrder($orderData) {
    // Process the order
    $order = createOrder($orderData);
    
    // Background tasks execute in FIFO order after response
    Defer::terminate(function() use ($order) {
        updateInventory($order); // Executes first
    });
    
    Defer::terminate(function() use ($order) {
        sendConfirmationEmail($order); // Executes second
    });
    
    Defer::terminate(function() use ($order) {
        logOrderCompletion($order); // Executes third
    });
    
    return ['success' => true, 'order_id' => $order->id];
    // In FastCGI: Response sent immediately, then background tasks run
    // In other environments: Tasks run with fallback behavior
}
```

### Long-Running Process with Graceful Shutdown

```php
// Set up graceful shutdown (LIFO execution)
Defer::global(fn() => echo "Shutdown complete\n");
Defer::global(function() {
    file_put_contents('/var/log/worker.log', "Worker stopped: " . date('c') . "\n", FILE_APPEND);
});
Defer::global(fn() => echo "Starting shutdown sequence...\n");

// Worker process
while (true) {
    $job = getNextJob();
    if (!$job) {
        sleep(1);
        continue;
    }
    
    processJob($job);
}
// LIFO cleanup runs on any termination across all platforms
```

## Execution Order Summary

- **Function Scope**: LIFO (Last In, First Out) - like a stack
- **Global Scope**: LIFO (Last In, First Out) - like a stack  
- **Terminate Scope**: FIFO (First In, First Out) - like a queue

This design ensures proper resource cleanup for function and global scopes while maintaining logical execution order for background tasks.

## Platform Compatibility

The library is truly **platform-agnostic** and provides robust signal handling across all environments:

- **Windows**: Native signal handling with `sapi_windows_set_ctrl_handler()`
- **Unix/Linux**: PCNTL signals, process monitoring, STDIN monitoring
- **All Platforms**: Fallback mechanisms ensure cleanup always works
- **Web/CLI**: Automatic environment detection and appropriate handler selection

No matter your platform or environment, defer callbacks will execute reliably.

## Limitations

1. **Terminate Defers**: Work optimally in FastCGI environments; other environments use fallback methods
2. **Nested Exceptions**: Exceptions in defer callbacks are logged but don't propagate
3. **Memory Limits**: Defer stacks have size limits to prevent memory leaks
4. **Execution Order**: Function and Global defers use LIFO order, Terminate uses FIFO - plan accordingly

## License

MIT License - see [LICENSE](LICENSE) file for details.