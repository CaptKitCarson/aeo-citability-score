<?php
/**
 * LLM client — Pro tier, BYOAPIKEY.
 *
 * Supports OpenAI, Anthropic, Google Gemini. The user's API key is stored
 * in wp_options (aecs_settings.llm_api_key) and never leaves their server.
 *
 * Public methods:
 *   - score_content( $title, $body ) : returns { score, reasoning, signals, weak_passages }
 *   - rewrite_paragraph( $original, $context ) : returns rewritten paragraph string
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AECS_LLM {

	/**
	 * @return array{ok:bool, data?:array, error?:string}
	 */
	public function score_content( $title, $body ) {
		$system = $this->rubric_prompt();
		$user   = "TITLE: {$title}\n\nBODY:\n{$body}";
		$json_response = $this->call( $system, $user, true );
		if ( ! $json_response['ok'] ) return $json_response;

		$data = $json_response['data'];
		if ( ! is_array( $data ) || ! isset( $data['score'] ) ) {
			return array( 'ok' => false, 'error' => 'LLM returned an unexpected shape' );
		}
		$data['score'] = max( 0, min( 100, (int) $data['score'] ) );
		$data['reasoning']     = (string) ( $data['reasoning'] ?? '' );
		$data['signals']       = (array) ( $data['signals']       ?? array() );
		$data['weak_passages'] = (array) ( $data['weak_passages'] ?? array() );
		return array( 'ok' => true, 'data' => $data );
	}

	public function rewrite_paragraph( $original, $context = '' ) {
		$system = "You rewrite paragraphs so they answer the reader's question in the first sentence, use plain nouns and concrete verbs, and stay under 90 words. Keep the author's voice. Return ONLY the rewritten paragraph as plain text — no quotes, no framing.";
		$user   = "CONTEXT: {$context}\n\nORIGINAL:\n{$original}\n\nREWRITE:";
		$res = $this->call( $system, $user, false );
		if ( ! $res['ok'] ) return $res;
		return array( 'ok' => true, 'data' => trim( (string) ( $res['data'] ?? '' ), " \t\r\n\"'" ) );
	}

	private function rubric_prompt() {
		return <<<PROMPT
You are an AI-search citability scorer. Score a piece of content 0-100 on how likely Google AI Overviews, ChatGPT web search, and Perplexity are to cite it.

CRITERIA (equal weight unless noted):
1. Front-loaded answer — the payload sentence is in the first 200 chars.
2. Extractable passages — H2/H3 headings are questions; each section answers cleanly.
3. Entity clarity — proper nouns are used consistently; the piece introduces the "who / what / where" early.
4. Source attribution — external links to authoritative sources.
5. Speakable / listenable — the lede reads naturally aloud.
6. Structure — Q&A blocks, definition-first paragraphs, tables where relevant.
7. Depth — enough substance that an AI could quote it; not thin.

RETURN JSON ONLY — no prose, no framing. Shape:
{
  "score": 0-100,
  "reasoning": "1-2 sentences on the overall verdict",
  "signals": {
    "front_loaded": {"score": 0-100, "note": "..."},
    "extractable": {"score": 0-100, "note": "..."},
    "entity_clarity": {"score": 0-100, "note": "..."},
    "source_attribution": {"score": 0-100, "note": "..."},
    "speakable": {"score": 0-100, "note": "..."},
    "structure": {"score": 0-100, "note": "..."},
    "depth": {"score": 0-100, "note": "..."}
  },
  "weak_passages": [
    {"snippet": "first 120 chars of the flagged passage", "why": "why this passage hurts citability", "suggestion": "1-sentence rewrite direction"}
  ]
}
PROMPT;
	}

	/**
	 * Route to the configured provider.
	 *
	 * @param string $system
	 * @param string $user
	 * @param bool   $expect_json
	 * @return array{ok:bool, data?:mixed, error?:string}
	 */
	private function call( $system, $user, $expect_json ) {
		$s = get_option( 'aecs_settings', array() );
		$provider = $s['llm_provider'] ?? 'none';
		$key      = $s['llm_api_key']  ?? '';
		$model    = $s['llm_model']    ?? '';
		if ( 'none' === $provider || empty( $key ) ) {
			return array( 'ok' => false, 'error' => 'LLM not configured. Add your provider + API key in Settings.' );
		}
		switch ( $provider ) {
			case 'openai':    return $this->openai( $key, $model ?: 'gpt-4o-mini',                $system, $user, $expect_json );
			case 'anthropic': return $this->anthropic( $key, $model ?: 'claude-3-5-haiku-latest', $system, $user, $expect_json );
			case 'gemini':    return $this->gemini( $key, $model ?: 'gemini-2.0-flash',           $system, $user, $expect_json );
		}
		return array( 'ok' => false, 'error' => 'Unknown provider: ' . $provider );
	}

	private function openai( $key, $model, $system, $user, $expect_json ) {
		$body = array(
			'model'    => $model,
			'messages' => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user',   'content' => $user ),
			),
			'max_tokens' => 1400,
			'temperature'=> 0.3,
		);
		if ( $expect_json ) $body['response_format'] = array( 'type' => 'json_object' );
		$res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );
		return $this->parse( $res, $expect_json, function ( $json ) {
			return $json['choices'][0]['message']['content'] ?? '';
		} );
	}

	private function anthropic( $key, $model, $system, $user, $expect_json ) {
		$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 30,
			'headers' => array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'model' => $model,
				'max_tokens' => 1400,
				'system' => $system . ( $expect_json ? "\n\nRespond ONLY with valid JSON. No prose." : '' ),
				'messages' => array( array( 'role' => 'user', 'content' => $user ) ),
			) ),
		) );
		return $this->parse( $res, $expect_json, function ( $json ) {
			return $json['content'][0]['text'] ?? '';
		} );
	}

	private function gemini( $key, $model, $system, $user, $expect_json ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents' => array( array( 'parts' => array( array( 'text' => $user ) ) ) ),
			'generationConfig' => array( 'temperature' => 0.3, 'maxOutputTokens' => 1400 ),
		);
		if ( $expect_json ) $body['generationConfig']['responseMimeType'] = 'application/json';
		$res = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );
		return $this->parse( $res, $expect_json, function ( $json ) {
			return $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
		} );
	}

	private function parse( $res, $expect_json, callable $extractor ) {
		if ( is_wp_error( $res ) ) return array( 'ok' => false, 'error' => $res->get_error_message() );
		$code = wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( $code >= 400 ) {
			$msg = is_array( $json ) ? ( $json['error']['message'] ?? $json['error'] ?? 'HTTP ' . $code ) : 'HTTP ' . $code;
			return array( 'ok' => false, 'error' => is_string( $msg ) ? $msg : ( 'HTTP ' . $code ) );
		}
		if ( ! is_array( $json ) ) return array( 'ok' => false, 'error' => 'LLM returned non-JSON envelope' );
		$content = call_user_func( $extractor, $json );
		if ( ! is_string( $content ) || '' === $content ) return array( 'ok' => false, 'error' => 'LLM returned empty content' );

		if ( $expect_json ) {
			$decoded = json_decode( $content, true );
			// Try to salvage a JSON blob wrapped in prose.
			if ( ! is_array( $decoded ) ) {
				if ( preg_match( '/\{.*\}/s', $content, $m ) ) {
					$decoded = json_decode( $m[0], true );
				}
			}
			if ( ! is_array( $decoded ) ) return array( 'ok' => false, 'error' => 'LLM returned invalid JSON' );
			return array( 'ok' => true, 'data' => $decoded );
		}
		return array( 'ok' => true, 'data' => $content );
	}
}
