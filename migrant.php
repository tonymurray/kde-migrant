<?php
set_time_limit(900);
const PATH = './';
const WARNING_SIZE = 100000; // bytes
const FOLDERS_SCAN = ['/.', '/.config/', '/.local/share/', '/.local/share/', '/.var/app/', '/snap/'];
const FILES_SCAN = ['/.config/', '/.local/share/', '/.local/share/', '/.var/app/', '/snap/'];
const KDE_MATCH = 'k|rc|pulse|session|gtk|x|autostart|xbel';

/* Determine the current step */
$currentStep = 0; // Begin
if ($_SERVER['REQUEST_METHOD'] == 'POST' and $_POST['step'] == 2) {
    $currentStep = 3;
}

if ($_SERVER['REQUEST_METHOD'] == 'GET' and file_exists(PATH . 'migrant1.config')) {
    $currentStep = 2;
}


function scanFolders($path)
{
    $home = $_SERVER['HOME'];
    $items = [];
    $folders = glob($home . $path . '*', GLOB_ONLYDIR);
    foreach ($folders as $folder) {
        if (!is_dir($folder) or basename($folder) == '.' or basename($folder) == '..') {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $file) {
            if ($file->isFile()) {
                $items[$folder] = $items[$folder] ?? 0;
                $items[$folder] += $file->getSize();
            }
        }
    }
    return $items;
}

function scanFiles($path)
{
    $home = $_SERVER['HOME'];
    $items = [];
    $files = glob($home . $path . '*');
    foreach ($files as $file) {
        if (is_dir($file) or basename($file) == '.' or basename($file) == '..') {
            continue;
        }
        $items[$file] = filesize($file);
    }
    return $items;
}

function displayItems($items, $path = '', $heading = '')
{
    $filtered_items = [];
    foreach ($items as $item => $size) {
        $parts = pathinfo($item);
        if ($parts['dirname'] == $path) {
            $filtered_items[$item] = $size;
        }
    }
    ksort($filtered_items);
    if (!empty($filtered_items)) {
        echo '<div class="space-y-6"><div class="flex flex-col items-start gap-1 pb-2 border-b border-kde-grey">';
        echo '<h2 class="text-xl font-bold tracking-tight mb-2 text-kde-text">' . $heading . ' ' . $path . '</h2>';
        foreach ($filtered_items as $item => $size) {
            $name = basename($item);
            echo '<div class="grid grid-cols-[20px,1fr] gap-1"><input type="checkbox" name="items[]" value="' . $item . '" id="' . $item . '" data-size="' . $size . '"> <label for="' . $item . '">' . $name;
            if ($size > WARNING_SIZE) {
                echo ' <span class="warning">(' . formatSize($size) . ')</span>';
            }
            echo '</label></div>';


        }

    echo '</div></div>';
    }

}

function formatSize($size)
{
    $mod = 1024;
    $units = explode(' ', 'B KB MB GB TB PB');
    for ($i = 0; $size > $mod; $i++) {
        $size /= $mod;
    }
    return round($size, 2) . ' ' . $units[$i];
}

$items = [];
$source = php_sapi_name();
if ($source == 'cli') {
    if (isset($argv[1]) and $argv[1] == 'scan') {
        echo "== STARTING SCAN ==\n";
        $total = count(FOLDERS_SCAN);
        foreach (FOLDERS_SCAN as $key => $folder) {
            $items = array_merge($items, scanFolders($folder));
            $percentage = round(($key + 1) / $total * 100);
            echo "\e[1;37m" . str_pad($percentage, 3, ' ', STR_PAD_LEFT) . "%\e[0m\r";
            flush();
        }
        $total = count(FILES_SCAN);
        foreach (FILES_SCAN as $key => $folder) {
            $items = array_merge($items, scanFiles($folder));
            $percentage = round(($key + 1) / $total * 100);
            echo "\e[1;37m" . str_pad($percentage, 3, ' ', STR_PAD_LEFT) . "%\e[0m\r";
            flush();
        }
        echo "\e[1;37m100%\e[0m\n";
        $config = ['home' => $_SERVER['HOME'], 'items' => $items];
        file_put_contents(PATH . 'migrant1.config', serialize($config));
        echo "== SCAN FINISHED ==\n";
        echo "\e[1;37mOpen migrant.php in your browser now to configure files and folders to migrate.\e[0m\n";
    } elseif (!isset($argv[1]) or (isset($argv[1]) and $argv[1] == 'help')) {
        echo "Usage: php migrant.php [COMMAND]\n\n";
        echo "  scan  \tScan user home directory\n";
        echo "  backup\tStart backup process\n";
        echo "  dryrun\tSimulate backup process (dry run)\n";
        echo "  help  \tShow this help\n\n";
        if (!file_exists(PATH . 'migrant1.config')) {
            echo "Home dir structure unknown: Use \e[0;30;103mscan\e[0m command to scan user home directory.\n";
        }
        if (file_exists(PATH . 'migrant2.config')) {
            echo "Configuration found: Use \e[0;30;103mbackup\e[0m to start backup process.\n";
        }
        echo "To run as different user: sudo -u [user] php migrant.php\n";
    } elseif (!file_exists(PATH . 'migrant2.config') and isset($argv[1]) and $argv[1] == 'backup') {
        echo "Configuration not found: \e[1;37mOpen migrant.php in your browser now to configure files and folders to migrate.\e[0m\n";
    } elseif (file_exists(PATH . 'migrant2.config') and file_exists(PATH . 'migrant1.config') and isset($argv[1]) and $argv[1] == 'backup') {
        echo "== STARTING BACKUP ==\n";
        $config = unserialize(file_get_contents(PATH . 'migrant1.config'));
        $home_directory = $config['home'];
        $config = unserialize(file_get_contents(PATH . 'migrant2.config'));
        $zip = new ZipArchive();
        $result = $zip->open(PATH . 'migrant.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result === true) {
            $config = unserialize(file_get_contents(PATH . 'migrant2.config'));
            if (!empty($config)) {
                $total = count($config);
                echo "\e[1;37mBacking up " . $total . " items\e[0m:\n";
                foreach ($config as $key => $item) {
                    $percentage = round(($key + 1) / $total * 100);
                    echo "\e[1;37m" . str_pad($percentage, 3, ' ', STR_PAD_LEFT) . "%\e[0m " . $item . "\n";
                    flush();
                    if (is_dir($item)) {
                        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($item), RecursiveIteratorIterator::LEAVES_ONLY);
                        foreach ($files as $name => $file) {
                            if (!$file->isDir()) {
                                $file_path = $file->getRealPath();
                                if ($file_path) {
                                    $relative_path = str_ireplace($home_directory . '/', '', $file_path);
                                    $zip->addFile($file_path, $relative_path);
                                }
                            }
                        }
                    } else {
                        $relative_path = str_ireplace($home_directory . '/', '', $item);
                        $zip->addFile($item, $relative_path);
                    }
                }
            }
            $zip->close();
            echo "== BACKUP FINISHED ==\n";
            echo "\e[1;37mYou can now take the migrant.zip file and unzip it on target machine.\e[0m\n";
            echo <<< EOT
        _
   ____/ \____
  / \e[1;37mCONGRATS!\e[0m \
  |  YOU ARE  |
  |   NOW A   |
  | \e[0;30;103m   KDE   \e[0m |
  \ \e[0;30;103m MIGRANT \e[0m /
   ====   ====
       \_/
EOT;
            echo "\n";
        } else {
            echo "\e[0;30;103mError creating migrant.zip file.\e[0m Check folder permissions and enable write access.\n";
        }
    } elseif (file_exists(PATH . 'migrant2.config') and isset($argv[1]) and $argv[1] == 'dryrun') {
        echo "\e[0;30;103m== Dry run only ==\e[0m\n";
        $config = unserialize(file_get_contents(PATH . 'migrant2.config'));
        if (!empty($config)) {
            $total = count($config);
            $sleeptime = 3 / $total * 1000000;
            echo "\e[1;37mBacking up " . $total . " items\e[0m:\n";
            foreach ($config as $key => $item) {
                $percentage = round(($key + 1) / $total * 100);
                echo "\e[1;37m" . str_pad($percentage, 3, ' ', STR_PAD_LEFT) . "%\e[0m " . $item . "\n";
                usleep($sleeptime);
            }
        }
        echo "\e[0;30;103m== Dry run only ==\e[0m\n";
    } else {
        echo "Unrecognized command. Use \e[0;30;103mhelp\e[0m to get usage info.\n";
    }
} else {

    ?>

<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>KDE Migrant </title>
<script src="script.js"></script>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        "kde-blue": "#3daee9",
                        "kde-blue-dark": "#1d99f3",
                        "kde-grey": "#eff0f1",
                        "kde-text": "#232629",
                        "kde-border": "#bdc3c7",
                    },
                    fontFamily: {
                        "sans": ["Noto Sans", "sans-serif"]
                    },
                    borderRadius: {
                        "DEFAULT": "2px",
                        "md": "4px",
                        "lg": "6px",
                    },
                },
            },
        }

        
    </script>
<style type="text/tailwindcss">
        @layer base {
            body {
                @apply bg-[#fcfcfc] text-kde-text;
            }
        }
        .kde-nav-link {
            @apply px-4 py-2 text-sm font-medium hover:text-kde-blue transition-colors;
        }
        .breeze-card {
            @apply bg-white border border-kde-border/50 shadow-sm hover:shadow-md transition-shadow;
        }
        .tab-active {
            @apply border-b-2 border-kde-blue text-kde-blue;
        }
        .checkbox-custom {
            @apply h-4 w-4 rounded border-kde-border text-kde-blue focus:ring-kde-blue focus:ring-offset-0;
        }
    </style>
</head>
<body class="min-h-screen">
<header class="bg-[#232629] text-white">
<div class="max-w-[1200px] mx-auto px-6 h-10 flex items-center justify-between text-xs font-medium">

<div class="flex items-center gap-4">
<span class="GitHubLogoInline ml-4"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16">
  <path class="gh-mark" fill-rule="evenodd" fill="white" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
</svg></span>
<a class="hover:text-kde-blue" href="https://github.com/nekromoff/kde-migrant">GIT Hub Repositiory</a>
</div>
<div class="flex items-center gap-6">

<span><svg viewBox="0 0 24 24" role="img" xmlns="http://www.w3.org/2000/svg" width="24" height="24"><path fill="white" d="M13.881 0 9.89.382v16.435l3.949-.594V9.216l5.308 7.772 4.162-1.317-5.436-7.475 5.479-7.05L19.105.17 13.84 7.22zM4.834 4.005a.203.203 0 0 0-.123.059L3.145 5.63a.203.203 0 0 0-.03.248L4.949 8.9a7.84 7.84 0 0 0-.772 1.759l-3.367.7a.203.203 0 0 0-.162.199v2.215c0 .093.064.174.155.196l3.268.8a7.83 7.83 0 0 0 .801 2.03L2.98 19.683a.203.203 0 0 0 .027.254l1.566 1.567a.204.204 0 0 0 .249.03l2.964-1.8c.582.336 1.21.6 1.874.78l.692 3.325c.02.094.102.161.198.161h2.215a.202.202 0 0 0 .197-.155l.815-3.332a7.807 7.807 0 0 0 1.927-.811l2.922 1.915c.08.053.186.042.254-.026l1.567-1.566a.202.202 0 0 0 .03-.248l-1.067-1.758-.345.11a.12.12 0 0 1-.135-.047L17.371 15.8a6.347 6.347 0 1 1-8.255-8.674V5.488c-.401.14-.79.31-1.159.511l-.001-.002-2.99-1.96a.203.203 0 0 0-.132-.033z"></path></svg></span>
<a class="hover:text-kde-blue" href="https://kde.org" target="_blank">KDE.org</a>
<a class="hover:text-kde-blue" href="https://kde.org/plasma-desktop" target="_blank">Plasma</a>
<a class="hover:text-kde-blue" href="https://develop.kde.org/products/frameworks/" target="_blank">Frameworks</a>
<a class="hover:text-kde-blue" href="https://discuss.kde.org/" target="_blank">KDE discuss</a>
<a class="hover:text-kde-blue mr-4" href="https://wiki.kde.org/" target="_blank">KDE Wiki</a>
</div>
</div>
</header>
<nav class="sticky top-0 z-50 w-full border-b border-kde-border bg-white/95 backdrop-blur-sm">
<div class="max-w-[1200px] mx-auto px-6 h-16 flex items-center justify-between">
<div class="flex items-baseline gap-10">
<div class="flex items-center gap-3">
<div class="w-10 h-10">
<svg fill="none" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
<circle cx="24" cy="24" fill="#3daee9" r="22"></circle>
<path d="M24 8C15.1634 8 8 15.1634 8 24C8 32.8366 15.1634 40 24 40C32.8366 40 40 32.8366 40 24C40 15.1634 32.8366 8 24 8ZM24 11C31.1797 11 37 16.8203 37 24C37 31.1797 31.1797 37 24 37C16.8203 37 11 31.1797 11 24C11 16.8203 16.8203 11 24 11Z" fill="white"></path>
<path d="M24 14C18.4772 14 14 18.4772 14 24C14 29.5228 18.4772 34 24 34C29.5228 34 34 29.5228 34 24C34 18.4772 29.5228 14 24 14ZM24 17C27.866 17 31 20.134 31 24C31 27.866 27.866 31 24 31C20.134 31 17 27.866 17 24C17 20.134 20.134 17 24 17Z" fill="white"></path>
</svg>
</div>
<div class="leading-none">
<h1 class="text-xl font-bold text-kde-text">KDE</h1>
<span class="text-[14px] uppercase tracking-wider text-kde-blue font-bold">Migrate</span>
</div>
</div>
<div class="hidden lg:flex items-center">
Migrate your existing KDE configuration to a new computer
</div>
</div>

</div>
</nav>
<main class="max-w-[1200px] mx-auto px-6 py-8">
<header class="mb-10 flex flex-col md:flex-row md:items-end justify-between gap-6">
<div>


</div>

</header>
<section class="mb-12">
<div class="bg-white rounded-lg shadow-sm border border-kde-border overflow-hidden">
<div class="flex border-b border-kde-border bg-kde-grey/30">
<button  data-tab="tab1" class="tabbtns <?php echo ($currentStep === 0) ? 'tab-active' : 'tabnotactive'; ?> px-8 py-4 text-sm font-bold flex items-center gap-2">
<span data-tab="tab1" class="tabbtns material-symbols-outlined text-[20px]">desktop_windows</span>
                    About
                </button>
<button data-tab="tab2" class="tabbtns <?php echo ($currentStep === 1) ? 'tab-active' : 'tabnotactive'; ?> px-8 py-4 text-sm font-bold text-slate-500 hover:text-kde-text border-b-2 border-transparent transition-all flex items-center gap-2">
<span data-tab="tab2" class="tabbtns material-symbols-outlined text-[20px]">layers</span>
                    Step 1
                </button>
<button data-tab="tab3" class="tabbtns <?php echo ($currentStep === 2) ? 'tab-active' : 'tabnotactive'; ?> px-8 py-4 text-sm font-bold text-slate-500 hover:text-kde-text border-b-2 border-transparent transition-all flex items-center gap-2">
<span data-tab="tab3" class="tabbtns material-symbols-outlined text-[20px]">tune</span>
                    Step 2
                </button>
<button  data-tab="tab4" class="tabbtns <?php echo ($currentStep === 3) ? 'tab-active' : 'tabnotactive'; ?> px-8 py-4 text-sm font-bold text-slate-500 hover:text-kde-text border-b-2 border-transparent transition-all flex items-center gap-2">
<span data-tab="tab4" class="material-symbols-outlined text-[20px]">layers</span>
                    Step 3
                </button>
 <button  data-tab="tab5" class="tabbtns <?php echo ($currentStep === 4) ? 'tab-active' : 'tabnotactive'; ?> px-8 py-4 text-sm font-bold text-slate-500 hover:text-kde-text border-b-2 border-transparent transition-all flex items-center gap-2">
<span data-tab="tab5" class="material-symbols-outlined text-[20px]">layers</span>
                    Migrate data
                </button>
<button  data-tab="tab6" class="tabbtns <?php echo ($currentStep === 5) ? 'tab-active' : 'tabnotactive'; ?> px-8 py-4 text-sm font-bold text-slate-500 hover:text-kde-text border-b-2 border-transparent transition-all flex items-center gap-2">
<span data-tab="tab6" class="material-symbols-outlined text-[20px]">help</span>
                    Help and tips
                </button>
</div>
<div id="tab1" class="maintabs p-10 <?php echo ($currentStep === 0) ? 'initial' : 'hidden'; ?>">
<h2 class="text-3xl font-bold tracking-tight mb-2 text-kde-text">KDE Plasma Configuration migration</h2>
<p class="text-slate-600 max-w-2xl mb-4">
KDE Migrant allows you to migrate your existing KDE configuration to a new computer. Good when changing computers or cloning one user configuration for other users.</p>

<p class="text-slate-600 max-w-2xl mb-4">A single file browser and command-line script that allows you to backup your full or partial KDE configuration including apps, dotfiles and any customizations. It works for KDE Plasma widgets as well.</p>

<p class="text-slate-600 max-w-2xl mb-4">It creates a ZIP file that you can transfer to a different computer to unzip it.
            </p>
</div>
<div id="tab2" class="maintabs p-10  <?php echo ($currentStep === 1) ? 'initial' : 'hidden'; ?>">

<h2 class="text-3xl font-bold tracking-tight mb-2 text-kde-text">Create Configuration file</h2>
<p class="text-slate-600 max-w-2xl mb-4">The first step is to run the PHP script on the <span class="font-bold">command line</span> as follows:</p>
  <div class="bg-black text-white rounded-lg p-4 font-mono">
        <span class="text-gray">$</span> php migrant.php scan
    </div>

<?php 
if ($_SERVER['REQUEST_METHOD'] == 'GET' and file_exists(PATH . 'migrant1.config')) {
 echo '<p class="text-slate-600 max-w-2xl mt-4">This step has already been carried out and the file <strong>migrant1.config</strong> was created</p>'; 
}
else {
   echo '<p class="text-slate-600 max-w-2xl mt-4">Please run the script in your terminal to create the file (<strong>migrant1.config</strong>), then return to this browser page and refresh</p>'; 
}
?>

</div>

<div id="tab3" class="maintabs p-10  <?php echo ($currentStep === 2) ? 'initial' : 'hidden'; ?>">
    <h2 class="text-3xl font-bold tracking-tight mb-2 text-kde-text">Choose what to migrate</h2>

<?php 
if (file_exists(PATH . 'migrant1.config')) {
        echo '<fieldset id="options">
        <input type="checkbox" name="' . KDE_MATCH . '" id="' . KDE_MATCH . '"><label for="' . KDE_MATCH . '"> KDE</label>
        <input class="ml-4" type="checkbox" name="plasma" id="plasma"><label for="plasma"> Plasma</label>
        <input class="ml-4" type="checkbox" name="var/app" id="var/app"><label for="var/app"> Flatpaks</label>
        <input class="ml-4" type="checkbox" name="snap" id="snap"><label for="snap"> Snaps</label>
        </fieldset>';

        $config = unserialize(file_get_contents(PATH . 'migrant1.config'));
        $home = $config['home'];
        $items = $config['items'];
        echo '<form id="items" method="post" action="migrant.php">';
        echo '<input type="hidden" name="step" value="2">';
        echo '<input class="bg-kde-blue hover:bg-kde-blue-dark text-white px-5 py-2.5 rounded-md text-sm font-semibold flex items-center gap-2 shadow-sm transition-colors mt-5 mb-5" type="submit" value="Create backup configuration">';
        echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-16 gap-y-12">';


        displayItems($items, $home . '/.config');
        displayItems($items, $home . '/.local/share');
        displayItems($items, $home . '/.var/app', 'Flatpak:');
        displayItems($items, $home . '/snap', 'Snapcraft:');
        displayItems($items, $home);
        echo '</div>
        <input class="bg-kde-blue hover:bg-kde-blue-dark text-white px-5 py-2.5 rounded-md text-sm font-semibold flex items-center gap-2 shadow-sm transition-colors mt-5 mb-5" type="submit" value="Create backup configuration">';
        echo '</form>';
}
else {
    echo '<p class="mt-4">Please follow step 1 to proceed</p>';
}
        ?>
</div>

<div id="tab4" class="maintabs p-10  <?php echo ($currentStep === 3) ? 'initial' : 'hidden'; ?>">
<?php     
if ($_SERVER['REQUEST_METHOD'] == 'POST' and $_POST['step'] == 2) {
        $config = $_POST['items'];
        echo '<h2 class="text-3xl font-bold tracking-tight mb-2 text-kde-text">Create the backup</h2>';
        if (!empty($config)) {
            file_put_contents(PATH . 'migrant2.config', serialize($config));
            echo '<div class="mqb mqd mqg mrf mrk mrl"><p class="mb-4">Backup settings have been saved. </p>

            <p class="mt-4">On your <span class="font-bold">command line</span> do as follows:</p>
<p class="mt-4 mb-4 font-bold">Dry-run</p>
<p class="mt-4 mb-4">Simulate backup based on existing configuration with:</p>

            <div class="bg-black text-white rounded-lg p-4 font-mono">
                <span class="text-gray">$</span> php migrant.php dryrun
            </div>
            <p class="mt-4 mb-4 font-bold">Create backup</p>
<p class="mt-4 mb-4">Create the backup with:</p>
            <div class="bg-black text-white rounded-lg p-4 font-mono">
                <span class="text-gray">$</span> php migrant.php backup
            </div>
            </div>';
        } else {
            echo '<div class="warning"><p>No backup settings have been selected. Go to Step 2.</p></div>';
        }
    }

    else {
        echo '<p>Please follow previous steps to proceed</p>';

    }
    ?>
</div>
<div id="tab5" class="maintabs p-10  <?php echo ($currentStep === 4) ? 'initial' : 'hidden'; ?>">
<h2 class="text-3xl font-bold tracking-tight mb-2 text-kde-text">Migrate data</h2>
<p class="mt-4 mb-4">The next step is to copy the zip file <span class="font-bold"> migrant.zip</span> to the target computer, and unzip it in (your) home folder, then restart your session (log-out and log-in again), or reboot your computer</p>
<p class="mt-4 mb-4">Note: it is advisable to backup the data on the target computer first - the existing data will replace previous data</p>

</div>
<div id="tab6" class="maintabs p-10  <?php echo ($currentStep === 4) ? 'initial' : 'hidden'; ?>">
<h2 class="text-3xl font-bold tracking-tight mb-2 text-kde-text">Help and tips</h2>
<p class="mt-4 mb-4"><strong>Tips</strong></p>
<p class="mt-4 mb-4">Copying your settings from one computer to another will work best if both computers are running the same version of KDE / Plasma. </p>
<p class="mt-4 mb-4">You can inspect this file (migrant.php) to see how the backup works. If you are unsure: (1) we suggest that you do not proceed, or (2) Copy only some  data at a time -- in step 2 (3) Test on a non-production computer (4) Ask someone for advice </p>

           <p class="mt-4 mb-4"><strong>FAQ</strong></p>

   <p class="mt-4 mb-4 font-bold"> Is it possible to add different folders to back up (e.g. not located in user home directory)?</p>

<p class="mt-4 mb-4">Yes, edit migrant.php and edit these constants: FOLDERS_SCAN and FILES_SCAN. Add paths to scan. Note that user has to have read access to them in order to back them up.</p>

<p class="mt-4 mb-4 font-bold">How can I change matching pattern for one-click group such as KDE checkbox on top?</p>

<p class="mt-4 mb-4">Edit KDE_MATCH constant and add your pattern separated by | pipe character. E.g. add |user to include all folders+files containing user in their name.</p>


</div>
</div>
</div>
</section>
<section>
<h3 class="text-xl font-bold mb-6 text-kde-text">Resources</h3>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
<div class="breeze-card p-6 flex flex-col items-start rounded-md">
<div class="w-12 h-12 bg-kde-blue/10 rounded-full flex items-center justify-center mb-4">
<span class="material-symbols-outlined text-kde-blue">auto_stories</span>
</div>
<h4 class="font-bold text-kde-text mb-2">Guides</h4>
<p class="text-sm text-slate-600 mb-4 flex-grow">View latest info on GIT hub</p>
<a class="text-sm font-bold text-kde-blue hover:underline inline-flex items-center gap-1" href="https://github.com/nekromoff/kde-migrant">GIT repository <span class="material-symbols-outlined text-xs">open_in_new</span></a>
</div>
<div class="breeze-card p-6 flex flex-col items-start rounded-md">
<div class="w-12 h-12 bg-kde-blue/10 rounded-full flex items-center justify-center mb-4">
<span class="material-symbols-outlined text-kde-blue">forum</span>
</div>
<h4 class="font-bold text-kde-text mb-2">Community issues</h4>
<p class="text-sm text-slate-600 mb-4 flex-grow">Connect with other users and developers</p>
<a class="text-sm font-bold text-kde-blue hover:underline inline-flex items-center gap-1" href="https://github.com/nekromoff/kde-migrant/issues">Issues <span class="material-symbols-outlined text-xs">open_in_new</span></a>
</div>
<div class="breeze-card p-6 flex flex-col items-start rounded-md">
<div class="w-12 h-12 bg-kde-blue/10 rounded-full flex items-center justify-center mb-4">
<span class="material-symbols-outlined text-kde-blue">bug_report</span>
</div>
<h4 class="font-bold text-kde-text mb-2">Report a Bug</h4>
<p class="text-sm text-slate-600 mb-4 flex-grow">Help us improve this tool.</p>
<a class="text-sm font-bold text-kde-blue hover:underline inline-flex items-center gap-1" href="https://github.com/nekromoff/kde-migrant/issuesc">File Bug <span class="material-symbols-outlined text-xs">open_in_new</span></a>
</div>
<div class="breeze-card p-6 flex flex-col items-start rounded-md">
<div class="w-12 h-12 bg-kde-blue/10 rounded-full flex items-center justify-center mb-4">
<span class="material-symbols-outlined text-kde-blue">volunteer_activism</span>
</div>
<h4 class="font-bold text-kde-text mb-2">Donate</h4>
<p class="text-sm text-slate-600 mb-4 flex-grow">Support the development of this project</p>
<a class="text-sm font-bold text-kde-blue hover:underline inline-flex items-center gap-1" href="https://ko-fi.com/dusoft">Support this project <span class="material-symbols-outlined text-xs">favorite</span></a>
</div>
</div>
</section>
</main>
<footer class="mt-20 border-t border-kde-border py-12 bg-white">

</footer>





    <?php
   echo '<style>
        .info { background: #AAFFFF; }
        .warning { background: #FFD6DA; }
        .error { background: #FFAFB0; }
    </style>';
    echo "<script>



        document.querySelectorAll('#options input[type=checkbox]').forEach(function(parent_el) {
            parent_el.addEventListener('click', (event)=> {
                let total=0;
                document.querySelectorAll('#items input[type=checkbox]').forEach(function(el) {
                    parent_ids=[parent_el.id];
                    if (parent_el.id.indexOf('|')) {
                        parent_ids=parent_el.id.split('|');
                    }
                    for (i=0; i<parent_ids.length; i++) {
                        parent_id=parent_ids[i];
                        if (parent_id.length==1 && el.id.toLowerCase().indexOf('/'+parent_id)!=-1) {
                            el.checked = parent_el.checked;
                            break;
                        } else if (parent_id.length>1 && el.id.toLowerCase().indexOf(parent_id)!=-1) {
                            el.checked = parent_el.checked;
                            break;
                        }
                    }
                });
                countSize();
            });
        });
        document.querySelectorAll('#items input[type=checkbox]').forEach(function(el) {
            el.addEventListener('click', (event)=> {
                countSize();
            });
        
    });
    ";
    // copied from: https://stackoverflow.com/questions/15900485/correct-way-to-convert-size-in-bytes-to-kb-mb-gb-in-javascript
    echo '
    function countSize() {
        var total=0;
        document.querySelectorAll("#items input[type=checkbox]").forEach(function(el) {
            if (el.checked==true) {
                total=total+el.dataset.size*1;
            }
        });
        document.querySelectorAll("input[type=submit]").forEach(function(el) {
            var add="";
            if (total>0) {
                add=" ("+formatSize(total)+")";
            }
            el.value="Create backup configuration"+add;
        });
    }
    function formatSize(bytes, decimals = 2) {
        if (!+bytes) return "0 Bytes";
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ["B", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math . pow(k, i)) . toFixed(dm))} ${sizes[i]}`;
    }
    </script>';
   
}
