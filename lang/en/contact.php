<?php

return [
    'page_title' => 'Contact',
    'eyebrow' => 'Customer support',
    'heading' => 'Get in touch',
    'subheading' => 'Send us questions about products, orders, or wholesale. We will reply as soon as possible.',
    'sent_status' => 'Thanks. Your message has been sent successfully.',
    'captcha_failed' => 'Security verification failed. Please try again.',
    'validation' => [
        'required' => 'The :attribute field is required.',
        'email' => 'The :attribute field must be a valid email address.',
        'accepted' => 'You must accept :attribute.',
        'min_string' => 'The :attribute field must be at least :min characters.',
        'max_string' => 'The :attribute field may not be greater than :max characters.',
        'security_check' => 'security check',
        'inline' => [
            'name_required' => 'Please enter your full name.',
            'email_required' => 'Please enter your email address.',
            'email_invalid' => 'Please enter a valid email address.',
            'message_required' => 'Please enter your message.',
            'message_min' => 'Message must be at least 10 characters.',
            'accept_terms' => 'You must accept GDPR consent.',
        ],
    ],
    'form' => [
        'name' => 'Full name',
        'email' => 'Email',
        'phone' => 'Phone (optional)',
        'subject' => 'Subject',
        'default_subject' => 'Contact form inquiry',
        'message' => 'Message',
        'accept_terms' => 'I agree with GDPR consent and personal data processing.',
        'submit' => 'Send message',
    ],
    'direct' => [
        'title' => 'Direct contact',
        'email' => 'Email',
        'phone' => 'Phone',
        'response_time' => 'Response time',
        'response_fallback' => 'Within business hours',
    ],
    'help' => [
        'title' => 'Quick help',
        'body' => 'For order questions, include your order number and contact phone so we can help you immediately.',
    ],
];
