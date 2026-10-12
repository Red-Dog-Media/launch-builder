<?php
namespace LaunchBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Legal page text: privacy policy, terms of service and accessibility statement as Markdown with Business Info tokens
 * ({{lb.name}}, {{lb.email}}...), so a later change to Business Info reaches the pages. Every text starts with a notice that
 * the business owner has to review it; the page stays a draft until that notice is removed (see Launch::REVIEW_CLASS).
 *
 * The text message (SMS) consent paragraph follows the carrier registration rules for business texting (A2P 10DLC):
 * who sends, what the messages are about, how often, rates, how to stop and get help, that consent is not a condition of
 * a purchase, and that mobile numbers and consent are never shared for marketing. It appears in the privacy policy and in
 * the terms, which is where carriers look for it.
 *
 * These are starting texts, not legal advice.
 */
final class Launch_Legal {

	/** Texts this class can write. */
	const TYPES = [ 'privacy', 'terms', 'accessibility' ];

	/** The default page title and slug of each text. */
	public static function page( string $type ): array {
		$map = [
			'privacy'       => [ 'title' => __( 'Privacy Policy', 'launch-builder' ), 'slug' => 'privacy-policy' ],
			'terms'         => [ 'title' => __( 'Terms of Service', 'launch-builder' ), 'slug' => 'terms-of-service' ],
			'accessibility' => [ 'title' => __( 'Accessibility Statement', 'launch-builder' ), 'slug' => 'accessibility' ],
		];
		return $map[ $type ] ?? [ 'title' => '', 'slug' => '' ];
	}

	/**
	 * The text message consent options with their defaults.
	 *
	 * @param mixed $in true, or { program, frequency, help }.
	 * @return array{program:string,frequency:string,help:string}
	 */
	public static function sms_options( $in ): array {
		$in = is_array( $in ) ? $in : [];
		$s = static function ( $v, string $d ): string {
			return is_string( $v ) && '' !== trim( $v ) ? trim( wp_strip_all_tags( $v ) ) : $d;
		};
		return [
			'program'   => $s( $in['program'] ?? '', __( 'service requests, appointment reminders and updates about your job', 'launch-builder' ) ),
			'frequency' => $s( $in['frequency'] ?? '', __( 'Message frequency varies', 'launch-builder' ) ),
			'help'      => $s( $in['help'] ?? '', __( 'reply HELP, or contact us at {{lb.email}} or {{lb.phone}}', 'launch-builder' ) ),
		];
	}

	/** The short label for the consent checkbox next to a phone field. */
	public static function consent_label( $in = true ): string {
		$o = self::sms_options( $in );
		/* translators: %s: what the messages are about */
		return sprintf( __( 'I agree to receive text messages from {{lb.name}} about %s. Consent is not a condition of purchase. Message and data rates may apply. Reply STOP to opt out.', 'launch-builder' ), $o['program'] );
	}

	/** The SMS consent paragraphs (Markdown). */
	public static function sms_markdown( $in = true ): string {
		$o = self::sms_options( $in );
		$p = [];
		/* translators: %s: what the messages are about */
		$p[] = sprintf( __( 'By giving us your mobile number and agreeing on our forms, or by sending us a text first, you agree that {{lb.name}} may text you about %s.', 'launch-builder' ), $o['program'] );
		$p[] = __( 'Consent to receive text messages is not a condition of any purchase.', 'launch-builder' ) . ' ' . $o['frequency'] . '. ' . __( 'Message and data rates may apply.', 'launch-builder' );
		/* translators: %s: how to get help */
		$p[] = sprintf( __( 'You can opt out at any time by replying STOP. For help, %s. Carriers are not liable for delayed or undelivered messages.', 'launch-builder' ), $o['help'] );
		$p[] = __( 'We do not share your mobile number or your text messaging consent with third parties or affiliates for marketing or promotional purposes.', 'launch-builder' );
		return implode( "\n\n", $p );
	}

	/** The review notice block (it carries the class the audit looks for). */
	public static function notice_block(): string {
		$text = '<strong>' . esc_html__( 'Client must review.', 'launch-builder' ) . '</strong> ' . esc_html__( 'This text was generated from a template. The business owner (ideally with a lawyer) has to read it, correct it for the business and delete this notice before the page is published.', 'launch-builder' );
		return '<!-- wp:group {"className":"' . Launch::REVIEW_CLASS . '"} -->' . "\n" . '<div class="wp-block-group ' . Launch::REVIEW_CLASS . '"><!-- wp:paragraph -->' . "\n" . '<p>' . $text . '</p>' . "\n" . '<!-- /wp:paragraph --></div>' . "\n" . '<!-- /wp:group -->';
	}

	/**
	 * The Markdown of one text.
	 *
	 * @param string $type         privacy | terms | accessibility.
	 * @param mixed  $sms          false = no text message section; true or options.
	 * @param string $privacy_path Path of the privacy policy page (the terms link to it).
	 */
	public static function markdown( string $type, $sms = false, string $privacy_path = '/privacy-policy/' ): string {
		switch ( $type ) {
			case 'privacy':
				return self::privacy( $sms );
			case 'terms':
				return self::terms( $sms, $privacy_path );
			case 'accessibility':
				return self::accessibility();
		}
		return '';
	}

	private static function join( array $parts ): string {
		return implode( "\n\n", array_filter( $parts, 'strlen' ) );
	}

	private static function privacy( $sms ): string {
		$p = [];
		$p[] = __( 'Last updated: {{lb.year}}', 'launch-builder' );
		$p[] = __( 'This policy explains what {{lb.name}} ("we", "us") collects when you visit {{site.url}}, call us, text us or ask for a quote, how we use it and what you can do about it.', 'launch-builder' );
		$p[] = '## ' . __( 'Information we collect', 'launch-builder' );
		$p[] = '- **' . __( 'What you give us', 'launch-builder' ) . '**: ' . __( 'your name, phone number, email address, service address and the details of the work you ask about, when you fill in a form, call, text or email us.', 'launch-builder' );
		$p[] = '- **' . __( 'What we collect automatically', 'launch-builder' ) . '**: ' . __( 'your IP address, browser, device, the pages you view and how you found us, through cookies and similar tools.', 'launch-builder' );
		$p[] = '- **' . __( 'What we get from others', 'launch-builder' ) . '**: ' . __( 'contact details from lead and review platforms when you ask them to connect you with us.', 'launch-builder' );
		$p[] = '## ' . __( 'How we use it', 'launch-builder' );
		$p[] = '- ' . __( 'To answer you, give quotes, schedule and carry out the work, and send invoices.', 'launch-builder' );
		$p[] = '- ' . __( 'To contact you by phone, text or email about your request, appointment or job.', 'launch-builder' );
		$p[] = '- ' . __( 'To run, secure and improve our website and advertising.', 'launch-builder' );
		$p[] = '- ' . __( 'To follow the law and protect our rights.', 'launch-builder' );
		if ( $sms ) {
			$p[] = '## ' . __( 'Text messages', 'launch-builder' );
			$p[] = self::sms_markdown( $sms );
		}
		$p[] = '## ' . __( 'Who we share it with', 'launch-builder' );
		$p[] = __( 'We share information only with the people who help us serve you (scheduling, payment, messaging, hosting and analytics providers), when the law requires it, or in a sale or transfer of the business. We do not sell your personal information.', 'launch-builder' );
		$p[] = '## ' . __( 'Cookies and analytics', 'launch-builder' );
		$p[] = __( 'We use cookies and similar tools to keep the site working, measure visits and measure our advertising. You can block or delete cookies in your browser settings; parts of the site may then work less well.', 'launch-builder' );
		$p[] = '## ' . __( 'Your choices', 'launch-builder' );
		$p[] = '- ' . ( $sms ? __( 'Reply STOP to any text message to stop texts, or follow the unsubscribe link in an email.', 'launch-builder' ) : __( 'Follow the unsubscribe link in an email, or tell us you would rather not be contacted.', 'launch-builder' ) );
		$p[] = '- ' . __( 'Ask us what we hold about you, to correct it or to delete it, by contacting us below.', 'launch-builder' );
		$p[] = '## ' . __( 'Keeping it safe', 'launch-builder' );
		$p[] = __( 'We keep information only as long as we need it for the reasons above or the law requires, and we protect it with reasonable safeguards. No method of storage or transmission is completely secure.', 'launch-builder' );
		$p[] = '## ' . __( 'Children', 'launch-builder' );
		$p[] = __( 'Our website and services are not directed to children under 13 and we do not knowingly collect their information.', 'launch-builder' );
		$p[] = '## ' . __( 'Changes', 'launch-builder' );
		$p[] = __( 'We may update this policy. The date at the top shows when it last changed.', 'launch-builder' );
		$p[] = '## ' . __( 'Contact us', 'launch-builder' );
		$p[] = __( '{{lb.name}}, {{lb.address}}. Email [{{lb.email}}](mailto:{{lb.email}}) or call [{{lb.phone}}](#lb-phone_link).', 'launch-builder' );
		return self::join( $p );
	}

	private static function terms( $sms, string $privacy_path ): string {
		$p = [];
		$p[] = __( 'Last updated: {{lb.year}}', 'launch-builder' );
		$p[] = __( 'These terms apply to your use of {{site.url}} and to the services of {{lb.name}} ("we", "us"). By using the site or asking for our services you agree to them.', 'launch-builder' );
		$p[] = '## ' . __( 'Estimates and services', 'launch-builder' );
		$p[] = __( 'Quotes and estimates are based on what you tell us and what we can see. The price, scope and schedule of a job are fixed by the written agreement or invoice we give you, and may change if the conditions turn out to differ.', 'launch-builder' );
		$p[] = '## ' . __( 'Scheduling and cancellations', 'launch-builder' );
		$p[] = __( 'Appointment windows are estimates. Please tell us as early as you can if you need to change or cancel an appointment. Any fee for a late cancellation or a missed visit will be told to you when you book.', 'launch-builder' );
		$p[] = '## ' . __( 'Payment', 'launch-builder' );
		$p[] = __( 'Payment is due as stated on your estimate or invoice. We may charge interest or collection costs on overdue amounts where the law allows.', 'launch-builder' );
		$p[] = '## ' . __( 'Warranty', 'launch-builder' );
		$p[] = __( 'Any warranty on our work is the one stated in your written agreement or invoice. It does not cover damage caused by misuse, other contractors or normal wear.', 'launch-builder' );
		if ( $sms ) {
			$p[] = '## ' . __( 'Text messaging program', 'launch-builder' );
			$p[] = self::sms_markdown( $sms );
			/* translators: %s: path of the privacy policy page */
			$p[] = sprintf( __( 'See also our [Privacy Policy](%s).', 'launch-builder' ), esc_url_raw( $privacy_path ) );
		}
		$p[] = '## ' . __( 'Using this website', 'launch-builder' );
		$p[] = __( 'The site and everything on it belongs to us or our licensors. You may view it and use it to contact us, but you may not copy it, interfere with it or use it unlawfully. Information on the site is general and is not a quote or professional advice.', 'launch-builder' );
		$p[] = '## ' . __( 'Disclaimer and limits', 'launch-builder' );
		$p[] = __( 'The site is provided as it is. To the extent the law allows, we are not liable for indirect or consequential losses, and our total liability for any claim is limited to the amount you paid us for the service in question.', 'launch-builder' );
		$p[] = '## ' . __( 'Governing law', 'launch-builder' );
		$p[] = __( 'These terms are governed by the laws of {{lb.region|the state where we operate}}, and disputes belong in the courts there.', 'launch-builder' );
		$p[] = '## ' . __( 'Contact us', 'launch-builder' );
		$p[] = __( '{{lb.name}}, {{lb.address}}. Email [{{lb.email}}](mailto:{{lb.email}}) or call [{{lb.phone}}](#lb-phone_link).', 'launch-builder' );
		return self::join( $p );
	}

	private static function accessibility(): string {
		$p = [];
		$p[] = __( '{{lb.name}} wants everyone to be able to use {{site.url}}, including people who use assistive technology.', 'launch-builder' );
		$p[] = '## ' . __( 'Our goal', 'launch-builder' );
		$p[] = __( 'We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.1 level AA. We check colour contrast, keyboard use, headings, link and button labels and image descriptions when we change the site.', 'launch-builder' );
		$p[] = '## ' . __( 'Known limits', 'launch-builder' );
		$p[] = __( 'Some parts of the site come from other providers (forms, maps, review widgets) and may not yet meet the same standard. We are working with them to improve this.', 'launch-builder' );
		$p[] = '## ' . __( 'Tell us about a problem', 'launch-builder' );
		$p[] = __( 'If something on the site is hard to use, email [{{lb.email}}](mailto:{{lb.email}}) or call [{{lb.phone}}](#lb-phone_link) and tell us the page and what went wrong. We will answer and, where we can, give you the information another way.', 'launch-builder' );
		return self::join( $p );
	}
}
