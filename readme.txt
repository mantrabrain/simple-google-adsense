=== AdFlow - Easy Google AdSense Integration ===
Contributors: MantraBrain, gangadharkashyap
Tags: google, adsense, ads, google adsense, monetization
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

The easiest way to integrate Google AdSense into your website. Supports Auto Ads and Manual Ads with shortcodes and Gutenberg blocks.

== Description ==

**AdFlow** is the most user-friendly plugin for integrating Google AdSense into your website. Whether you're a beginner or an advanced user, this plugin provides everything you need to monetize your content effectively and increase your website revenue.

### 🚀 Key Features

**Auto Ads (Recommended for Beginners)**
- ✅ **One-click setup** - Just enter your Publisher ID
- ✅ **AI-powered placement** - Google's machine learning optimizes ad positions
- ✅ **Automatic optimization** - Maximizes earnings while maintaining user experience
- ✅ **Mobile responsive** - Works perfectly on all devices

**Manual Ads (For Advanced Users)**
- ✅ **Shortcodes** - Easy-to-use shortcodes for precise ad placement
- ✅ **Gutenberg blocks** - Visual block editor integration
- ✅ **Multiple ad types** - Banner, In-article, In-feed, and Matched Content
- ✅ **Flexible formatting** - Auto, Fluid, Rectangle, and more

**Professional Features**
- ✅ **Clean admin interface** - Modern, intuitive settings page
- ✅ **Comprehensive documentation** - Built-in help and guidance
- ✅ **Error handling** - Clear messages when configuration is needed
- ✅ **Responsive design** - Works on all screen sizes

### 🎯 Perfect For
- **Bloggers** wanting to monetize their content
- **Website owners** looking for easy AdSense integration
- **Content creators** seeking to maximize ad revenue
- **Developers** who need flexible ad placement options
- **Beginners** who want a simple setup process

### 💰 Monetization Benefits
- **Increase Revenue** - Easy AdSense integration for better earnings
- **Optimize Performance** - AI-powered ad placement for maximum CTR
- **Mobile Optimization** - Responsive ads that work on all devices
- **User Experience** - Smart ad placement that doesn't hurt engagement

== Installation ==

### Method 1: Dashboard Installation
1. Go to **Plugins → Add New** in your admin dashboard
2. Search for "AdFlow"
3. Click **Install Now** and then **Activate**

### Method 2: Manual Upload
1. Download the plugin ZIP file
2. Go to **Plugins → Add New → Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Click **Activate Plugin**

== Quick Start Guide ==

### Step 1: Get Your Publisher ID
1. Go to [adsense.google.com](https://www.google.com/adsense)
2. Sign in with your Google account
3. Click **Account → Settings → Account information**
4. Copy your **Publisher ID** (starts with "pub-")

**📖 Official Guide:** [How to find your Publisher ID](https://support.google.com/adsense/answer/105516?hl=en)

### Step 2: Configure the Plugin
1. Go to **Settings → AdFlow** in your admin dashboard
2. Enter your Publisher ID in the **Publisher ID** field
3. Choose **Auto Ads** (recommended) or **Manual Ads**
4. Click **Save Settings**

### Step 3: Set Up Auto Ads (Recommended)
1. Go to your [Google AdSense account](https://www.google.com/adsense)
2. Navigate to **Ads → Auto ads**
3. Enable the ad types you want
4. Click **Save**

That's it! Your ads will start appearing within 24-48 hours.

**📖 Official Guide:** [How to set up Auto ads](https://support.google.com/adsense/answer/7020288?hl=en)

== Detailed Usage ==

### Auto Ads Setup
Auto Ads is the easiest way to monetize your website. Google's AI automatically places ads where they perform best, maximizing your revenue while maintaining excellent user experience.

**To enable Auto Ads:**
1. Enter your Publisher ID in the plugin settings
2. Check "Enable Auto Ads"
3. Save settings
4. Configure Auto Ads in your AdSense account

### Manual Ads Setup
For precise control over ad placement, use Manual Ads with shortcodes or Gutenberg blocks.

#### Shortcodes
Use these shortcodes in your posts, pages, or widgets:

**Basic Ad:**
```
[adsense ad_slot="YOUR_AD_SLOT_ID"]
```

**Banner Ad:**
```
[adsense_banner ad_slot="YOUR_AD_SLOT_ID"]
```

**In-Article Ad:**
```
[adsense_inarticle ad_slot="YOUR_AD_SLOT_ID"]
```

**In-Feed Ad:**
```
[adsense_infeed ad_slot="YOUR_AD_SLOT_ID"]
```

**Matched Content:**
```
[adsense_matched_content ad_slot="YOUR_AD_SLOT_ID"]
```

#### Gutenberg Blocks
The AdFlow plugin includes a powerful Gutenberg block for easy visual ad placement.

**Step-by-Step Block Setup:**

1. **Edit any post or page** in the WordPress block editor
2. **Click the "+" button** to add a new block
3. **Search for "AdFlow Ad"** in the block search
4. **Add the block** to your content
5. **Configure the block settings** in the right sidebar:

**Block Configuration Options:**
- **Ad Slot ID** - Enter your Google AdSense ad unit ID (e.g., 1234567890)
- **Ad Type** - Choose from:
  - Banner Ad (standard display ads)
  - In-Article Ad (fluid ads within content)
  - In-Feed Ad (native-looking ads)
  - Matched Content (content recommendation ads)
- **Ad Format** - Select from:
  - Auto (responsive, recommended)
  - Fluid (adaptive sizing)
  - Auto Relaxed (flexible sizing)
  - Rectangle (300x250, 336x280)
  - Horizontal (728x90, 970x90)
  - Vertical (160x600, 300x600)
- **Full Width Responsive** - Enable for responsive ad sizing

**Block Preview:**
The block shows a live preview in the editor with your configuration, making it easy to see how your ad will appear.

**Getting Your Ad Slot ID:**
1. Go to your [Google AdSense account](https://www.google.com/adsense)
2. Navigate to **Ads → By ad unit**
3. Click **Create new ad unit**
4. Choose your ad type and format
5. Click **Create** and copy the Ad Slot ID

**📖 Official Guide:** [How to create ad units](https://support.google.com/adsense/answer/9183566?hl=en)

### Getting Your Ad Slot ID
1. Go to your AdSense account
2. Navigate to **Ads → By ad unit**
3. Click **Create new ad unit**
4. Choose ad type and format
5. Click **Create** and copy the Ad Slot ID

**📖 Official Guide:** [How to create ad units](https://support.google.com/adsense/answer/9183566?hl=en)

== Frequently Asked Questions ==

= Can this plugin help me sign up for Google AdSense? =

No, this plugin only helps integrate AdSense into your website. You need to apply for AdSense separately at adsense.google.com.

= How long does it take for ads to appear? =

Auto Ads typically appear within 24-48 hours. Manual ads appear immediately once configured.

= Will this plugin work with my existing AdSense setup? =

Yes, this plugin works alongside existing AdSense configurations without conflicts.

= Can I use both Auto Ads and Manual Ads? =

Yes, you can enable both options simultaneously for maximum flexibility and revenue optimization.

= What's the difference between Auto Ads and Manual Ads? =

Auto Ads uses Google's AI to automatically place ads, while Manual Ads let you control exact placement using shortcodes or blocks.

= How do I get my Publisher ID? =

Go to adsense.google.com → Account → Settings → Account information. Your Publisher ID starts with "pub-".

**📖 Official Guide:** [How to find your Publisher ID](https://support.google.com/adsense/answer/105516?hl=en)

= How do I create Ad Slot IDs for Manual Ads? =

In AdSense, go to Ads → By ad unit → Create new ad unit. Choose your ad type and copy the generated Ad Slot ID.

**📖 Official Guide:** [How to create ad units](https://support.google.com/adsense/answer/9183566?hl=en)

= How do I use the Gutenberg block for ads? =

1. Edit any post or page in the block editor
2. Click the "+" button and search for "AdFlow Ad"
3. Add the block to your content
4. In the block settings (right sidebar), enter your Ad Slot ID
5. Choose your ad type and format
6. The block will show a preview of your configuration

= What ad types are available in the Gutenberg block? =

The block supports Banner Ads, In-Article Ads, In-Feed Ads, and Matched Content ads. Each type is optimized for different placements and user experiences.

= Can I monitor earnings with this plugin? =

No, this plugin focuses on ad integration. Check your earnings in your AdSense account dashboard.

= Are the ads mobile-responsive? =

Yes, all ads are automatically responsive and work perfectly on mobile devices.

= I'm not seeing any ads. What should I check? =

1) Verify your Publisher ID is correct, 2) Check that Auto Ads are enabled in AdSense, 3) Wait 24-48 hours for ads to appear.

= My Manual Ads aren't showing. What's wrong? =

1) Make sure you've entered the correct Ad Slot ID, 2) Check that Manual Ads are enabled in plugin settings, 3) Verify your Ad Slot ID is active in AdSense.

= Can I use this plugin with other ad networks? =

This plugin is specifically designed for Google AdSense. For other networks, you'll need different plugins.

= How can I maximize my AdSense revenue? =

Use Auto Ads for automatic optimization, combine with Manual Ads for strategic placement, and ensure your content is high-quality and engaging.

== Screenshots ==

1. **Settings Page** - Clean, modern interface with comprehensive documentation
2. **Gutenberg Block** - Visual block editor integration
3. **Shortcode Examples** - Easy-to-use shortcodes for manual placement

== Changelog ==

= 1.2.0 | 2025/07/28 =
* **NEW**: Added Manual Ads support with shortcodes and Gutenberg blocks
* **NEW**: Implemented comprehensive documentation sidebar
* **NEW**: Added multiple shortcode types (banner, in-article, in-feed, matched content)
* **NEW**: Created Gutenberg block with visual editor integration
* **NEW**: Enhanced error handling and user guidance
* **NEW**: Added Publisher ID and Ad Slot ID creation guides
* **IMPROVED**: Modern, responsive admin interface design
* **IMPROVED**: Better Auto Ads integration and configuration
* **IMPROVED**: Enhanced CSS styling for all ad types
* **IMPROVED**: Added help links to official Google documentation

= 1.1 | 2024/03/22 =
* Tested with version 6.5
* Basic Auto Ads functionality

== Upgrade Notice ==

### Version 1.2.0
This major update introduces Manual Ads support, making the plugin suitable for both beginners and advanced users. New features include:
- Shortcodes for precise ad placement
- Gutenberg blocks for visual editing
- Comprehensive documentation
- Enhanced user interface

== Support ==

**Need Help?**
- Check the built-in documentation in the plugin settings
- Visit our [support page](https://wordpress.org/support/plugin/simple-google-adsense/)
- Review the [FAQ section](#frequently-asked-questions) above

**Found a Bug?**
Please report issues on our [GitHub repository](https://github.com/mantrabrain/simple-google-adsense) or the [support forum](https://wordpress.org/support/plugin/simple-google-adsense/).

== More Products from MantraBrain ==

**[Yatra Travel Booking Plugin](https://wpyatra.com/?ref=wporghtaccess)**
Complete travel booking solution for tour operators and travel agencies.

**[Ultimate Image Watermark](https://wordpress.org/plugins/ultimate-watermark/)**
Automatically add watermarks to images as they're uploaded to your site.
