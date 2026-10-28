<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            [
                'question' => 'How do I create an account?',
                'answer' => 'Click the <strong>Register</strong> button on the homepage, fill in your name, email, and password, then submit the form. You\'ll receive a verification email to activate your account.',
            ],
            [
                'question' => 'How do I verify my account?',
                'answer' => 'After registering, check your email for a verification link. Click it to verify your email address. You can also resend the verification email from your dashboard if needed.',
            ],
            [
                'question' => 'What subscription plans are available?',
                'answer' => 'We offer three plans: <strong>Verified</strong> (Free with ID/card verification), <strong>Business</strong> (Free with ID/card verification, includes selling features), and <strong>Premium Business</strong> (£19.99/month with full platform access and priority support).',
            ],
            [
                'question' => 'How do I upgrade my subscription?',
                'answer' => 'Go to <strong>Subscription</strong> in the sidebar, choose your desired plan, and click <strong>Get Verified</strong> or <strong>Upgrade</strong>. Follow the verification steps to complete the process.',
            ],
            [
                'question' => 'Can I change my profile picture?',
                'answer' => 'Yes. Navigate to <strong>My Profile</strong> in the dashboard, click the avatar area, and upload a new image. You can upload, preview, and change your picture using the image uploader.',
            ],
            [
                'question' => 'How do I contact other users?',
                'answer' => 'Once verified, you can send connection and follow requests to other users. You can also message them directly depending on your subscription plan features.',
            ],
            [
                'question' => 'What is the blue verification badge?',
                'answer' => 'The blue verification badge confirms that your identity has been verified through our ID and card verification process. It helps build trust with other users on the platform.',
            ],
            [
                'question' => 'How do I reset my password?',
                'answer' => 'On the login page, click <strong>Forgot password?</strong>, enter your registered email, and we\'ll send you a password reset link. Follow the link to set a new password.',
            ],
            [
                'question' => 'Is my personal data secure?',
                'answer' => 'Yes. We use industry-standard security measures to protect your data. Please review our <a href="'.url('/privacy-policy').'">Privacy Policy</a> for detailed information on how we collect, use, and safeguard your information.',
            ],
            [
                'question' => 'How do I delete my account?',
                'answer' => 'Go to <strong>My Profile</strong> in the dashboard, scroll to the bottom, and click <strong>Delete Account</strong>. This action is irreversible and will remove all your data from our platform.',
            ],
        ];

        foreach ($faqs as $faq) {
            Faq::firstOrCreate(['question' => $faq['question']], ['answer' => $faq['answer']]);
        }
    }
}
