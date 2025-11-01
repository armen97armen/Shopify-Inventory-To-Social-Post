# Dark Moody Theme - Usage Guide

## Overview

A reusable dark theme with glassy effects and neon accents perfect for futuristic, moody interfaces.

## Color Palette

### Base Colors
```css
--color-black-void: #000000        /* Pure black background */
--color-dark-carbon: #0a0a0a       /* Slightly lighter black */
--color-glass-dark: #1a1a1a        /* Glass card base */
--color-chrome-accent: #2a2a2a     /* Card accents */
```

### Neon Accents
```css
--color-neon-blue: #0080ff         /* Primary accent */
--color-neon-pink: #ff0080         /* Secondary accent */
```

### Text Colors (High Contrast)
```css
--color-text-primary: rgba(255, 255, 255, 0.95)   /* Main text */
--color-text-secondary: rgba(255, 255, 255, 0.70)  /* Labels */
--color-text-tertiary: rgba(255, 255, 255, 0.50)   /* Hints */
--color-text-placeholder: rgba(255, 255, 255, 0.30) /* Inputs */
```

### Border Colors
```css
--color-border-subtle: rgba(255, 255, 255, 0.05)  /* Barely visible */
--color-border-visible: rgba(255, 255, 255, 0.10)  /* Subtle */
--color-border-active: rgba(255, 255, 255, 0.20)   /* Focus state */
```

## Tailwind Custom Colors

Add to your `tailwind.config.js`:

```js
colors: {
    'black-void': '#000000',
    'dark-carbon': '#0a0a0a',
    'glass-dark': '#1a1a1a',
    'chrome-accent': '#2a2a2a',
    'neon-blue': '#0080ff',
    'neon-pink': '#ff0080',
}
```

## Component Patterns

### Glass Card
```html
<div class="backdrop-blur-lg bg-glass-dark/30 border border-white/10 rounded-2xl p-8 shadow-2xl">
    <!-- content -->
</div>
```

### Glass Button
```html
<button class="bg-gradient-to-r from-neon-blue/20 to-neon-pink/20 backdrop-blur-md border border-white/10 text-white font-bold rounded-xl hover:from-neon-blue/30 hover:to-neon-pink/30 hover:border-white/20 transition-all transform hover:scale-[1.02]">
    Click Me
</button>
```

### Glass Input
```html
<input class="bg-chrome-accent/80 backdrop-blur-sm border border-white/20 rounded-xl text-white placeholder-white/40 focus:ring-2 focus:ring-neon-blue/50 focus:border-white/30">
```

### Loading Ring
```html
<div class="relative w-32 h-32">
    <div class="absolute inset-0 border-2 border-white/10 rounded-full"></div>
    <div class="absolute inset-0 border-2 border-transparent border-t-neon-blue rounded-full animate-spin"></div>
    <div class="absolute inset-0 flex items-center justify-center">
        <div class="w-3 h-3 bg-neon-blue rounded-full animate-pulse shadow-lg shadow-neon-blue/50"></div>
    </div>
</div>
```

### Text Hierarchy
```html
<h1 class="text-white">Primary Heading</h1>
<h2 class="text-white/90">Secondary Heading</h2>
<p class="text-white/70">Body Text</p>
<span class="text-white/50">Muted Text</span>
```

## Contrast Guidelines

**Always ensure minimum contrast:**
- Primary text: `white` or `white/95`
- Secondary text: `white/70` to `white/90`
- Interactive elements: Use `white/20` or higher for borders
- Backgrounds: Use at least 30% opacity for glass effects

**Bad Examples (too dark):**
- `text-white/20` ❌
- `border-white/5` ❌
- `bg-glass-dark/10` ❌

**Good Examples:**
- `text-white/90` ✅
- `border-white/20` ✅
- `bg-glass-dark/40` ✅

## Animations

### Fade In Up
```html
<div class="animate-[fadeInUp_0.5s_ease-out]">
```

### Pulse
```html
<div class="animate-pulse">
```

### Spin
```html
<div class="animate-spin">
```

## Usage Examples

### Dark Modal
```html
<div class="fixed inset-0 bg-black-void/80 backdrop-blur-sm">
    <div class="backdrop-blur-lg bg-glass-dark/40 border border-white/20 rounded-2xl p-8">
        <h2 class="text-white text-2xl font-bold">Modal Title</h2>
        <p class="text-white/90 mt-4">Content here</p>
    </div>
</div>
```

### Dark Form
```html
<form class="backdrop-blur-lg bg-glass-dark/30 border border-white/10 rounded-2xl p-8">
    <label class="text-white/90 font-semibold">Label</label>
    <input class="bg-chrome-accent/80 border border-white/20 text-white">
    <button class="bg-gradient-to-r from-neon-blue/20 to-neon-pink/20 border border-white/10 text-white">
        Submit
    </button>
</form>
```

## Browser Support

- Modern browsers with backdrop-filter support
- Graceful degradation: removes blur on older browsers
- Tested on Chrome, Firefox, Safari, Edge

## Accessibility

- WCAG AA compliant contrast ratios
- Focus states with visible rings
- Keyboard navigation support
- Screen reader friendly

## Quick Reference

| Element | Class Pattern |
|---------|--------------|
| Background | `bg-black-void` |
| Glass Card | `backdrop-blur-lg bg-glass-dark/30 border border-white/10` |
| Primary Button | `bg-gradient-to-r from-neon-blue/20 to-neon-pink/20 border border-white/10` |
| Text Primary | `text-white` |
| Text Secondary | `text-white/90` |
| Border Subtle | `border-white/10` |
| Border Visible | `border-white/20` |
| Input | `bg-chrome-accent/80 border border-white/20 text-white` |

## Remember

✅ Always use white/opacity values 70% or higher for text  
✅ Use borders at 10% or higher for visibility  
✅ Ensure glass backgrounds are at least 30% opacity  
✅ Test in both light and dark viewing environments  
❌ Never use white/5 or white/10 for readable text  
❌ Never stack dark elements without contrast  

