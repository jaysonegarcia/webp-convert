<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin orchestrator. Builds the subsystems and wires their hooks.
 *
 * Dependency graph:
 *   Settings   — no deps
 *   W3TC       — no deps (guards every call with class_exists)
 *   Converter  — Settings, W3TC
 *   URLRewriter — no deps (uses Paths static helpers)
 *   AdminPages — Settings, Converter
 */
class WebP_Convert_Plugin
{
    /** @var WebP_Convert_Settings */
    private $settings;

    /** @var WebP_Convert_W3TC_Integration */
    private $w3tc;

    /** @var WebP_Convert_Converter */
    private $converter;

    /** @var WebP_Convert_URL_Rewriter */
    private $url_rewriter;

    /** @var WebP_Convert_Admin_Pages */
    private $admin_pages;

    public function __construct()
    {
        $this->settings     = new WebP_Convert_Settings();
        $this->w3tc         = new WebP_Convert_W3TC_Integration();
        $this->converter    = new WebP_Convert_Converter($this->settings, $this->w3tc);
        $this->url_rewriter = new WebP_Convert_URL_Rewriter();
        $this->admin_pages  = new WebP_Convert_Admin_Pages($this->settings, $this->converter);
    }

    public function register_hooks(): void
    {
        $this->settings->register_hooks();
        $this->w3tc->register_hooks();
        $this->converter->register_hooks();
        $this->url_rewriter->register_hooks();
        $this->admin_pages->register_hooks();
    }
}
