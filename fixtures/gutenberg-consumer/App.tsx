import { useRef, useState } from 'react';
import { Button, SafeAreaView, Text } from 'react-native';
import {
  GutenbergEditor,
  type GutenbergEditorRef,
} from 'wp-native-gutenberg';

export default function App() {
  const editorRef = useRef<GutenbergEditorRef>(null);
  const [status, setStatus] = useState('Loading editor...');

  async function requestContent() {
    try {
      const result = await editorRef.current?.requestContent();
      setStatus(result ? `Content length: ${result.content.length}` : 'Editor unavailable');
    } catch (error) {
      setStatus(error instanceof Error ? error.message : String(error));
    }
  }

  return (
    <SafeAreaView style={{ flex: 1 }}>
      <Text accessibilityLiveRegion="polite">{status}</Text>
      <Button title="Request content" onPress={requestContent} />
      <GutenbergEditor
        ref={editorRef}
        initialTitle="Fixture draft"
        initialContent="<!-- wp:paragraph --><p>Fixture content.</p><!-- /wp:paragraph -->"
        onReady={() => {
          setStatus('Editor ready');
          void requestContent();
        }}
        onError={({ nativeEvent }) => setStatus(nativeEvent.message)}
        style={{ flex: 1 }}
      />
    </SafeAreaView>
  );
}
