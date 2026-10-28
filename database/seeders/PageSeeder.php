<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'sort_order' => 0,
                'slug' => 'about-us',
                'title' => 'About Us',
                'content' => '<h3>About Us</h3><p>We are a dynamic platform that connects professionals, businesses, and individuals. Our mission is to empower users to showcase their skills, grow their networks, and build meaningful relationships both professionally and socially.</p><p>Founded in 2026, we are committed to creating a trustworthy, transparent, and engaging community for everyone.</p>',
            ],
            [
                'sort_order' => 1,
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'content' => '<h3>Privacy Policy</h3><p>Your privacy is important to us. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our platform.</p><p>We do not sell or share your personal data with third parties for marketing purposes. All data is stored securely and used solely to enhance your experience on our platform.</p>',
            ],
            [
                'sort_order' => 2,
                'slug' => 'community-guidelines',
                'title' => 'Community Guidelines',
                'content' => '<h3>Community Guidelines</h3><p>Welcome to our community! Please follow these guidelines:</p><ul><li>Be respectful and kind to all members</li><li>Do not share inappropriate or harmful content</li><li>Respect intellectual property rights</li><li>No spam or misleading information</li><li>Protect your privacy and others\' privacy</li><li>Report any violations to our team</li></ul>',
            ],
            [
                'sort_order' => 3,
                'slug' => 'terms-and-conditions',
                'title' => 'Terms & Conditions',
                'content' => '<h3>Terms & Conditions</h3><p>By using our service, you agree to:</p><ul><li>Provide accurate information during registration</li><li>Maintain the security of your account</li><li>Not use the service for illegal activities</li><li>Accept our privacy policy and data handling practices</li><li>Understand that we may update these terms</li><li>Accept that violation may result in account termination</li></ul>',
            ],
        ];

        foreach ($pages as $page) {
            Page::updateOrCreate(['slug' => $page['slug']], $page);
        }
    }
}
