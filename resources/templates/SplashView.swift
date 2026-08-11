import SwiftUI

/// Mirrors LaunchScreen.storyboard exactly, so the hand-off from the launch
/// screen to SwiftUI is invisible rather than a second, different splash.
struct SplashView: View {
    var body: some View {
        ZStack {
            Color("LaunchBackground")
                .ignoresSafeArea()

            Image("LaunchIcon")
                .resizable()
                .aspectRatio(contentMode: .fit)
                .frame(width: __ICON_SIZE__, height: __ICON_SIZE__)
        }
        .ignoresSafeArea()
    }
}

#Preview {
    SplashView()
}
