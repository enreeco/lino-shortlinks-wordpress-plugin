const fs = require('fs');
const path = require('path');
const archiver = require('archiver');

// Configuration
const PLUGIN_FILE = 'wp-short-links.php';
const BUILD_DIR = 'build';
const RELEASE_DIR = 'release';
const EXCLUDE_PATTERNS = [
    '.git',
    '.gitignore',
    'build_script',
    'README.md',
    'build',
    'release',
    'node_modules',
    'package.json',
    'package-lock.json',
    '.vscode'
];

// Author information
const AUTHOR_NAME = 'Enrico Murru';
const AUTHOR_LINK = 'https://blog.enree.co';

/**
 * Extract plugin name and version from the main plugin file
 */
function extractPluginInfo() {
    const pluginFile = path.join(__dirname, '..', PLUGIN_FILE);
    const content = fs.readFileSync(pluginFile, 'utf8');
    
    // Extract plugin name from "Plugin Name: ..."
    const nameMatch = content.match(/Plugin Name:\s*(.+)/i);
    const pluginName = nameMatch ? nameMatch[1].trim() : 'wp-short-links';
    
    // Extract version from "Version: ..." or WP_SHORT_LINKS_VERSION constant
    let versionMatch = content.match(/Version:\s*([\d.]+)/i);
    if (!versionMatch) {
        versionMatch = content.match(/WP_SHORT_LINKS_VERSION['"]\s*,\s*['"]([\d.]+)['"]/);
    }
    const version = versionMatch ? versionMatch[1].trim() : '1.0.0';
    
    return { pluginName, version };
}

/**
 * Generate banner comment for PHP files (without opening tag)
 */
function generatePHPBanner(pluginName, version) {
    return `/**
 * Plugin: ${pluginName}
 * Version: ${version}
 * Author: ${AUTHOR_NAME}
 * Author URI: ${AUTHOR_LINK}
 *
 * @package ${pluginName.replace(/\s+/g, '-').toLowerCase()}
 */

`;
}

/**
 * Generate banner comment for JavaScript files
 */
function generateJSBanner(pluginName, version) {
    return `/**
 * Plugin: ${pluginName}
 * Version: ${version}
 * Author: ${AUTHOR_NAME}
 * Author URI: ${AUTHOR_LINK}
 */

`;
}

/**
 * Check if a file should be excluded
 */
function shouldExclude(filePath, rootDir) {
    const relativePath = path.relative(rootDir, filePath);
    const parts = relativePath.split(path.sep);
    
    // Check if any part matches exclude patterns
    for (const part of parts) {
        if (EXCLUDE_PATTERNS.includes(part)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get file extension
 */
function getFileExtension(filePath) {
    return path.extname(filePath).toLowerCase();
}

/**
 * Add banner to code file content
 */
function addBannerToFile(filePath, content, pluginName, version) {
    const ext = getFileExtension(filePath);
    
    if (ext === '.php') {
        // For PHP files, check if it starts with <?php
        if (content.trim().startsWith('<?php')) {
            const lines = content.split('\n');
            const firstLine = lines[0]; // Keep the <?php line
            const restOfContent = lines.slice(1).join('\n');
            
            // Insert banner comment after the opening <?php tag
            const banner = generatePHPBanner(pluginName, version);
            return firstLine + '\n' + banner + restOfContent;
        } else {
            // File doesn't start with <?php, add <?php tag and banner
            return '<?php\n' + generatePHPBanner(pluginName, version) + content;
        }
    } else if (ext === '.js') {
        // Check if file already has a banner (starts with /**)
        if (content.trim().startsWith('/**')) {
            // Find the end of the existing banner
            const bannerEnd = content.indexOf('*/');
            if (bannerEnd !== -1) {
                const afterBanner = content.substring(bannerEnd + 2).trim();
                return generateJSBanner(pluginName, version) + '\n' + afterBanner;
            }
        }
        return generateJSBanner(pluginName, version) + '\n' + content;
    }
    
    return content;
}

/**
 * Check if a file is a binary file (image, etc.)
 */
function isBinaryFile(filePath) {
    const ext = getFileExtension(filePath);
    const binaryExtensions = ['.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.webp', '.bmp', '.tiff', '.pdf', '.zip', '.woff', '.woff2', '.ttf', '.eot'];
    return binaryExtensions.includes(ext);
}

/**
 * Copy file with banner if it's a code file
 */
function copyFileWithBanner(srcPath, destPath, pluginName, version) {
    const ext = getFileExtension(srcPath);
    
    // Ensure destination directory exists
    const destDir = path.dirname(destPath);
    if (!fs.existsSync(destDir)) {
        fs.mkdirSync(destDir, { recursive: true });
    }
    
    // Handle binary files (images, fonts, etc.) - copy as-is without text encoding
    if (isBinaryFile(srcPath)) {
        const binaryContent = fs.readFileSync(srcPath); // Read as binary (no encoding)
        fs.writeFileSync(destPath, binaryContent); // Write as binary
        return;
    }
    
    // Handle text files (PHP, JS, CSS, etc.)
    const content = fs.readFileSync(srcPath, 'utf8');
    
    // Add banner only to code files
    let finalContent = content;
    /*
    //this may "hurt" the final code
    if (ext === '.php' || ext === '.js') {
        finalContent = addBannerToFile(srcPath, content, pluginName, version);
    }*/
    
    fs.writeFileSync(destPath, finalContent, 'utf8');
}

/**
 * Recursively copy files from source to build directory
 */
function copyFiles(srcDir, destDir, pluginName, version) {
    if (!fs.existsSync(srcDir)) {
        throw new Error(`Source directory does not exist: ${srcDir}`);
    }
    
    const entries = fs.readdirSync(srcDir, { withFileTypes: true });
    
    for (const entry of entries) {
        const srcPath = path.join(srcDir, entry.name);
        const destPath = path.join(destDir, entry.name);
        
        // Skip excluded files/directories
        if (shouldExclude(srcPath, path.join(__dirname, '..'))) {
            continue;
        }
        
        if (entry.isDirectory()) {
            // Recursively copy directory
            if (!fs.existsSync(destPath)) {
                fs.mkdirSync(destPath, { recursive: true });
            }
            copyFiles(srcPath, destPath, pluginName, version);
        } else if (entry.isFile()) {
            // Copy file with banner if it's a code file
            copyFileWithBanner(srcPath, destPath, pluginName, version);
        }
    }
}

/**
 * Create zip file from build directory
 */
function createZip(buildDir, outputPath, folderName) {
    return new Promise((resolve, reject) => {
        const output = fs.createWriteStream(outputPath);
        const archive = archiver('zip', {
            zlib: { level: 9 } // Maximum compression
        });
        
        output.on('close', () => {
            console.log(`✓ Zip file created: ${outputPath} (${archive.pointer()} bytes)`);
            resolve();
        });
        
        archive.on('error', (err) => {
            reject(err);
        });
        
        archive.pipe(output);
        
        // Add all files from build directory with folder name in ZIP
        // This ensures WordPress sees the plugin in a folder structure
        archive.directory(buildDir, folderName);
        
        archive.finalize();
    });
}

/**
 * Sanitize filename by removing invalid characters
 */
function sanitizeFileName(name) {
    // Replace invalid filename characters with dashes
    // Invalid chars: < > : " / \ | ? *
    let sanitized = name.replace(/[<>:"/\\|?*]/g, '-');
    // Replace spaces with dashes
    sanitized = sanitized.replace(/\s+/g, '-');
    // Remove multiple consecutive dashes
    sanitized = sanitized.replace(/-+/g, '-');
    // Remove leading/trailing dashes
    sanitized = sanitized.replace(/^-+|-+$/g, '');
    return sanitized.toLowerCase();
}

/**
 * Clean build and release directories
 */
function cleanDirectories() {
    const buildPath = path.join(__dirname, BUILD_DIR);
    const releasePath = path.join(__dirname, RELEASE_DIR);
    
    if (fs.existsSync(buildPath)) {
        fs.rmSync(buildPath, { recursive: true, force: true });
        console.log('✓ Cleaned build directory');
    }
    
    if (!fs.existsSync(releasePath)) {
        fs.mkdirSync(releasePath, { recursive: true });
        console.log('✓ Created release directory');
    }
}

/**
 * Main build function
 */
async function build() {
    console.log('🚀 Starting build process...\n');
    
    try {
        // Clean directories first (before any other operations)
        console.log('🧹 Cleaning build directory...');
        cleanDirectories();
        console.log('');
        
        // Extract plugin info
        const { pluginName, version } = extractPluginInfo();
        console.log(`📦 Plugin: ${pluginName}`);
        console.log(`📌 Version: ${version}\n`);
        
        // Setup paths
        const rootDir = path.join(__dirname, '..');
        const buildDir = path.join(__dirname, BUILD_DIR);
        const releaseDir = path.join(__dirname, RELEASE_DIR);
        
        // Create build directory
        if (!fs.existsSync(buildDir)) {
            fs.mkdirSync(buildDir, { recursive: true });
        }
        
        // Create plugin folder with consistent name (no version in folder name)
        const sanitizedPluginName = sanitizeFileName(pluginName);
        const pluginFolderName = sanitizedPluginName; // Consistent folder name across versions
        const pluginBuildDir = path.join(buildDir, pluginFolderName);
        
        // Copy files with banners into plugin folder
        console.log('📋 Copying files...');
        copyFiles(rootDir, pluginBuildDir, pluginName, version);
        console.log('✓ Files copied with banners\n');
        
        // Create zip file
        const zipFileName = `${sanitizedPluginName}-${version}.zip`;
        const zipPath = path.join(releaseDir, zipFileName);
        
        console.log('📦 Creating zip file...');
        // Create ZIP with plugin folder structure
        await createZip(pluginBuildDir, zipPath, pluginFolderName);
        
        console.log('\n✅ Build completed successfully!');
        console.log(`📦 Package: ${zipPath}`);
        
    } catch (error) {
        console.error('\n❌ Build failed:', error.message);
        process.exit(1);
    }
}

// Run build
build();

