// Copyright (C) 2026 Chris Huber
// SPDX-License-Identifier: GPL-2.0-or-later

import ExpoModulesCore

public final class WPNativeGutenbergModule: Module {
  public func definition() -> ModuleDefinition {
    Name("WPNativeGutenberg")

    View(GutenbergEditorView.self) {
      Prop("initialTitle") { (view: GutenbergEditorView, title: String) in
        view.initialTitle = title
      }

      Prop("initialContent") { (view: GutenbergEditorView, content: String) in
        view.initialContent = content
      }

      Events("onReady", "onError")

      OnViewDidUpdateProps { (view: GutenbergEditorView) in
        view.loadEditorIfNeeded()
      }

      AsyncFunction("requestContent") { (view: GutenbergEditorView) async throws in
        try await view.requestContent()
      }
    }
  }
}
